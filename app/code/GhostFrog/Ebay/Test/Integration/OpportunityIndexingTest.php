<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Test\Integration;

use GhostFrog\Ebay\Cron\RefreshComps;
use GhostFrog\Ebay\Model\Comps\Index as CompsIndex;
use GhostFrog\Ebay\Model\FinalPrice\Queue;
use GhostFrog\Ebay\Model\FinalPrice\Worker;
use GhostFrog\Ebay\Model\ModelKey\Classifier;
use GhostFrog\Ebay\Model\Opportunities\Index;
use GhostFrog\Ebay\Model\Opportunities\Rows;
use GhostFrog\Ebay\Model\ResourceModel\Listing;
use Magento\Backend\Model\Auth\Session;
use Magento\Config\Model\Config;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\ObjectManager\ConfigLoader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\View\Element\UiComponent\ContextFactory;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Framework\View\Element\UiComponentInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\User\Model\UserFactory;
use PHPUnit\Framework\TestCase;
use Throwable;

final class OpportunityIndexingTest extends TestCase
{
    private const PHONE = 'Samsung Galaxy S21 128GB SM-G991B';
    private const OTHER_PHONE = 'Samsung Galaxy S22 128GB SM-S901B';
    private const SOURCE = 'ebay-samsung';

    private static ObjectManagerInterface $objects;
    private ResourceConnection $resource;
    private AdapterInterface $db;
    private IndexerRegistry $indexers;
    private int $sequence = 0;
    private bool $fixturesAllowed = false;

    public static function setUpBeforeClass(): void
    {
        self::$objects = Bootstrap::create(BP, $_SERVER)->getObjectManager();
        self::$objects->get(State::class)->setAreaCode(Area::AREA_ADMINHTML);
        self::$objects->configure(self::$objects->get(ConfigLoader::class)->load(Area::AREA_ADMINHTML));
    }

    protected function setUp(): void
    {
        $this->resource = self::$objects->get(ResourceConnection::class);
        $this->db = $this->resource->getConnection();
        $this->indexers = self::$objects->get(IndexerRegistry::class);
        $testDb = getenv('GHOSTFROG_EBAY_TEST_DB');
        self::assertNotEmpty($testDb, 'Set GHOSTFROG_EBAY_TEST_DB to the disposable Magento database name.');
        self::assertSame($testDb, $this->db->fetchOne('SELECT DATABASE()'));
        self::assertSame(0, (int)$this->db->fetchOne('SELECT COUNT(*) FROM ' . $this->table(Listing::TABLE)));
        self::assertTrue($this->indexers->get(CompsIndex::ID)->isScheduled());
        self::assertTrue($this->indexers->get(Index::ID)->isScheduled());
        $this->fixturesAllowed = true;

        foreach (['live', 'ended', 'sold'] as $status) {
            $this->db->insertOnDuplicate($this->table('ghostfrog_ebay_listing_statuses'), [
                'status' => $status,
                'lifecycle_stage' => $status === 'live' ? 'live' : 'terminal',
                'is_live_like' => (int)($status === 'live'),
                'is_terminal' => (int)($status !== 'live'),
                'description' => $status,
            ], ['status']);
        }
    }

    protected function tearDown(): void
    {
        if (!$this->fixturesAllowed) {
            return;
        }

        $this->db->query('SET timestamp = 0');
        $this->db->delete($this->table('ghostfrog_ebay_listing_review_actions'));
        $this->db->delete($this->table(Listing::TABLE));
        $this->db->delete($this->table('core_config_data'), ['path IN (?)' => [
            'ghostfrog_ebay/general/enabled', 'ghostfrog_ebay/heartbeat/gf_heartbeat_enable_close',
            'ghostfrog_ebay/ebay_api/gf_allow_ebay_api', 'ghostfrog_ebay/comps/target_profit',
        ]]);
        self::$objects->get(ReinitableConfigInterface::class)->reinit();
        $this->indexers->get(CompsIndex::ID)->reindexAll();
        $this->indexers->get(Index::ID)->reindexAll();
    }

    public function testListingsEnterChangeAndLeaveTheGridThroughTheScheduledViews(): void
    {
        $this->sales(5);
        $id = $this->listing(['id' => 5_000_000_000, 'model_key' => null]);
        $better = $this->listing(['price_current' => 65, 'sale_type' => 'bin', 'end_time' => null]);
        $this->updateViews();
        self::assertFalse($this->opportunity($id));

        $this->change($id, ['model_key' => $this->modelKey(self::PHONE)]);
        $this->updateViews();
        self::assertSame(144.0, (float)$this->opportunity($id)['expected_profit']);
        self::assertSame(144.0, (float)$this->opportunity($id)['expected_roi_percent']);
        self::assertSame(194.0, (float)$this->opportunity($id)['max_safe_buy']);
        $this->assertGrid($better, $id);

        $this->change($id, ['price_current' => 180]);
        $this->updateViews();
        self::assertSame(64.0, (float)$this->opportunity($id)['expected_profit']);
        $this->change($id, ['price_current' => 200]);
        $this->updateViews();
        self::assertFalse($this->opportunity($id));

        $this->change($id, ['price_current' => 100, 'condition_grade' => 'C']);
        $this->updateViews();
        self::assertFalse($this->opportunity($id));
        $this->change($id, ['condition_grade' => 'B']);
        $this->updateViews();
        self::assertNotFalse($this->opportunity($id));

        $listing = $this->db->fetchRow($this->db->select()->from($this->table(Listing::TABLE))->where('id = ?', $id));
        $reviews = $this->table('ghostfrog_ebay_listing_review_actions');
        $this->db->insert($reviews, ['external_id' => $listing['external_id'], 'action' => 'down']);
        $this->updateViews();
        self::assertFalse($this->opportunity($id));
        $this->db->delete($reviews, ['external_id = ?' => $listing['external_id']]);
        $this->updateViews();
        self::assertNotFalse($this->opportunity($id));

        $text = $this->table('ghostfrog_ebay_listing_text_conditions');
        $this->db->insert($text, [
            'listing_id' => $id, 'external_id' => $listing['external_id'], 'model_name' => 'fixture',
            'text_grade' => 'B', 'assessment' => '{}', 'risk_flags' => '[]', 'review_required' => 1,
        ]);
        $this->updateViews();
        self::assertFalse($this->opportunity($id));
        $this->db->delete($text, ['listing_id = ?' => $id]);
        $this->updateViews();
        self::assertNotFalse($this->opportunity($id));

        $this->change($id, ['status' => 'ended']);
        $this->updateViews();
        self::assertFalse($this->opportunity($id));
        $this->change($id, ['status' => 'live']);
        $this->updateViews();
        self::assertNotFalse($this->opportunity($id));

        self::$objects->get(Index::class)->executeList([$id, (string)$id, $better]);
        self::$objects->get(Index::class)->executeRow($id);
        self::assertSame(2, (int)$this->db->fetchOne('SELECT COUNT(*) FROM ' . $this->table(Rows::TABLE)));

        // Advance only this test connection's clock so time passes without a listing write.
        $this->db->query('SET timestamp = UNIX_TIMESTAMP() + 3601');
        self::$objects->get(Index::class)->expire();
        self::assertFalse($this->opportunity($id));
        self::assertNotFalse($this->opportunity($better));
        $this->db->query('SET timestamp = UNIX_TIMESTAMP() + 86400');
        self::$objects->get(Index::class)->expire();
        self::assertFalse($this->opportunity($better));
    }

    public function testConfirmedSalesRefreshOnlyAffectedCohortsAndMatchAFullRebuild(): void
    {
        $lowest = $this->sales(1, self::PHONE, ['final_price' => 250])[0];
        $this->sales(1, self::PHONE, ['final_price' => 275]);
        $this->sales(1);
        $this->sales(1, self::PHONE, ['final_price' => 400]);
        $this->sales(5, self::OTHER_PHONE);
        $this->sales(1, self::PHONE, ['condition_grade' => 'A']);
        $first = $this->listing();
        $second = $this->listing(['price_current' => 130]);
        $unrelated = $this->listing(['title' => self::OTHER_PHONE]);
        $lastSale = $this->listing(['external_id' => 'v1|951111111111|0', 'status' => 'ended',
            'end_time' => gmdate('Y-m-d H:i:s', time() - 7200)]);
        $this->updateViews();
        self::assertFalse($this->opportunity($first));
        self::assertFalse($this->opportunity($second));

        $compTable = $this->table(CompsIndex::TABLE);
        $marker = '2000-01-01 00:00:00';
        $this->db->update($compTable, ['computed_at' => $marker], [
            '(model_key = ' . $this->db->quote($this->modelKey(self::OTHER_PHONE)) . " OR condition_grade = 'A')",
        ]);
        $this->change($unrelated, ['price_current' => 120]);
        $this->change($lastSale, ['status' => 'sold', 'finalized' => 1, 'final_price' => 600]);
        $this->indexers->get(CompsIndex::ID)->getView()->update();
        self::assertSame(5, (int)$this->comp(self::PHONE)['samples']);
        self::assertNotFalse($this->opportunity($first));
        self::assertNotFalse($this->opportunity($second));
        self::assertSame(100.0, (float)$this->opportunity($unrelated)['current_price']);
        self::assertSame($marker, $this->comp(self::OTHER_PHONE)['computed_at']);
        self::assertSame($marker, $this->comp(self::PHONE, 'A')['computed_at']);

        $comps = self::$objects->get(CompsIndex::class);
        $this->db->update($compTable, ['computed_at' => $marker], [
            'model_key = ?' => $this->modelKey(self::PHONE), 'condition_grade = ?' => 'B',
        ]);
        $comps->executeList([$lastSale, $lastSale]);
        self::assertSame($marker, $this->comp(self::PHONE)['computed_at']);

        // The newer representation of the same eBay item wins even across different model keys.
        $alias = $this->sales(1, self::OTHER_PHONE, ['external_id' => 'v2|951111111111|0',
            'end_time' => gmdate('Y-m-d H:i:s', time() - 3600)])[0];
        $comps->executeRow($alias);
        self::assertSame(4, (int)$this->comp(self::PHONE)['samples']);
        self::assertSame(6, (int)$this->comp(self::OTHER_PHONE)['samples']);
        self::assertFalse($this->opportunity($first));

        $this->change($alias, ['condition_grade' => 'A']);
        $this->updateViews();
        self::assertSame(5, (int)$this->comp(self::OTHER_PHONE)['samples']);
        self::assertSame(1, (int)$this->comp(self::OTHER_PHONE, 'A')['samples']);

        $this->change($alias, ['title' => self::PHONE, 'model_key' => $this->modelKey(self::PHONE), 'final_price' => 500]);
        $this->updateViews();
        self::assertFalse($this->comp(self::OTHER_PHONE, 'A'));
        self::assertSame(2, (int)$this->comp(self::PHONE, 'A')['samples']);

        $this->db->delete($this->table(Listing::TABLE), ['id = ?' => $alias]);
        $this->updateViews();
        self::assertSame(5, (int)$this->comp(self::PHONE)['samples']);
        self::assertSame(1, (int)$this->comp(self::PHONE, 'A')['samples']);
        self::assertNotFalse($this->opportunity($first));

        $this->change($lowest, ['final_price' => 400]);
        $this->indexers->get(CompsIndex::ID)->getView()->update();
        self::assertSame(400.0, (float)$this->opportunity($first)['sold_median']);
        self::assertSame(231.0, (float)$this->opportunity($first)['expected_profit']);
        $this->change($lowest, ['final_price' => 250]);
        $this->updateViews();

        $rows = $this->db->select()->from($this->table(Rows::TABLE))->order('listing_id');
        $compRows = $this->db->select()->from($compTable, [
            'model_key', 'condition_grade', 'samples', 'median_final_price', 'mean_final_price',
        ])->order(['model_key', 'condition_grade']);
        $before = $this->db->fetchAll($rows);
        $beforeComps = $this->db->fetchAll($compRows);
        $this->indexers->get(CompsIndex::ID)->reindexAll();
        $this->indexers->get(Index::ID)->reindexAll();
        self::assertSame($before, $this->db->fetchAll($rows));
        self::assertSame($beforeComps, $this->db->fetchAll($compRows));

        $config = self::$objects->create(Config::class);
        $config->setDataByPath('ghostfrog_ebay/comps/target_profit', '175');
        $config->save();
        self::assertTrue($this->indexers->get(Index::ID)->isInvalid());
        self::assertTrue($this->indexers->get(CompsIndex::ID)->isValid());
        $this->indexers->get(Index::ID)->reindexAll();
        self::assertFalse($this->opportunity($first));
    }

    public function testFailedOpportunityWriteRollsBackTheSaleIndexAndKeepsTheFinalPriceJob(): void
    {
        $this->sales(4);
        $live = $this->listing();
        $sale = $this->listing(['status' => 'ended', 'end_time' => gmdate('Y-m-d H:i:s', time() - 7200)]);
        $this->updateViews();

        $queue = self::$objects->get(Queue::class);
        $queue->fill(20);
        $checked = $queue->listing((string)$sale);
        self::assertNotNull($checked);
        self::assertTrue($queue->record((string)$sale, $checked, ['status' => 'sold', 'reason' => 'fixture', 'final_price' => 300]));
        $this->setWorkerConfig('ghostfrog_ebay/general/enabled', '1');
        $this->setWorkerConfig('ghostfrog_ebay/heartbeat/gf_heartbeat_enable_close', '1');
        $this->setWorkerConfig('ghostfrog_ebay/ebay_api/gf_allow_ebay_api', '0');
        self::$objects->get(ReinitableConfigInterface::class)->reinit();

        $trigger = $this->db->quoteIdentifier($this->table('gf_test_opportunity_write_failure'));
        $table = $this->db->quoteIdentifier($this->table(Rows::TABLE));
        $this->db->query("CREATE TRIGGER $trigger BEFORE INSERT ON $table FOR EACH ROW "
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture opportunity write failure'");
        $worker = self::$objects->get(Worker::class);
        $view = $this->indexers->get(CompsIndex::ID)->getView();
        try {
            $version = (int)$view->getState()->getVersionId();
            $this->assertWriteFailure($view->update(...));
            self::assertSame($version, (int)$view->getState()->getVersionId());
            $this->assertWriteFailure($worker->execute(...));
            self::assertFalse($this->opportunity($live));
            self::assertSame(4, (int)$this->comp(self::PHONE)['samples']);
            self::assertSame(1, (int)$queue->due(20)[0]['needs_comp']);
        } finally {
            $this->db->query("DROP TRIGGER $trigger");
        }

        $worker->execute();
        self::assertSame([], $queue->due(20));
        self::assertSame(5, (int)$this->comp(self::PHONE)['samples']);
        self::assertNotFalse($this->opportunity($live));
        $this->updateViews();
        self::assertSame(5, (int)$this->comp(self::PHONE)['samples']);

        self::$objects->get(RefreshComps::class)->execute();
        self::assertTrue($this->indexers->get(CompsIndex::ID)->isInvalid());
        self::assertTrue($this->indexers->get(Index::ID)->isInvalid());
    }

    private function assertWriteFailure(callable $write): void
    {
        try {
            $write();
        } catch (Throwable $exception) {
            self::assertStringContainsString('fixture opportunity write failure', $exception->getMessage());
            return;
        }
        self::fail('Expected the opportunity write to fail.');
    }

    private function assertGrid(int $top, int $next): void
    {
        $admin = self::$objects->get(UserFactory::class)->create()->getCollection()->getFirstItem();
        self::$objects->get(Session::class)->setUser($admin);
        $provider = $this->grid()->getContext()->getDataProvider();
        $provider->setLimit(1, 1);
        $data = $provider->getData();
        self::assertSame(2, $data['totalRecords']);
        self::assertSame([$top], array_map('intval', array_column($data['items'], 'listing_id')));
        self::assertSame('bin', $data['items'][0]['sale_type']);

        $provider = $this->grid()->getContext()->getDataProvider();
        $provider->setLimit(2, 1);
        self::assertSame([$next], array_map('intval', array_column($provider->getData()['items'], 'listing_id')));

        $grid = $this->grid();
        $provider = $grid->getContext()->getDataProvider();
        $filter = self::$objects->create(FilterBuilder::class)->setField('sale_type')->setValue('auction')->create();
        $provider->addFilter($filter);
        $data = json_decode($grid->render('json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['totalRecords']);
        self::assertSame($next, (int)$data['items'][0]['listing_id']);
        $actions = $data['items'][0]['actions'];
        self::assertStringStartsWith('https://www.ebay.co.uk/itm/', $actions['ebay']['href']);
        self::assertStringContainsString('/listing/edit/id/' . $next . '/', $actions['edit']['href']);
    }

    private function grid(): UiComponentInterface
    {
        $name = 'ghostfrog_ebay_opportunities';
        $context = self::$objects->get(ContextFactory::class)->create([
            'namespace' => $name, 'pageLayout' => self::$objects->get(LayoutInterface::class),
        ]);
        $grid = self::$objects->get(UiComponentFactory::class)->create($name, null, ['context' => $context]);
        $this->prepareComponent($grid);
        return $grid;
    }

    private function prepareComponent(UiComponentInterface $component): void
    {
        foreach ($component->getChildComponents() as $child) {
            $this->prepareComponent($child);
        }
        $component->prepare();
    }

    private function updateViews(): void
    {
        $this->indexers->get(CompsIndex::ID)->getView()->update();
        $this->indexers->get(Index::ID)->getView()->update();
    }

    private function modelKey(string $title): string
    {
        $key = self::$objects->get(Classifier::class)->key(self::SOURCE, $title, []);
        self::assertNotSame('unknown', $key);
        return $key;
    }

    private function listing(array $fields = []): int
    {
        $title = $fields['title'] ?? self::PHONE;
        $this->db->insert($this->table(Listing::TABLE), $fields + [
            'external_id' => (string)(950000000000 + ++$this->sequence),
            'source' => self::SOURCE,
            'title' => $title,
            'model_key' => $this->modelKey($title),
            'condition_grade' => 'B',
            'status' => 'live',
            'sale_type' => 'auction',
            'first_seen' => gmdate('Y-m-d H:i:s'),
            'end_time' => gmdate('Y-m-d H:i:s', time() + 3600),
            'price_current' => 100,
        ]);
        return (int)($fields['id'] ?? $this->db->lastInsertId());
    }

    private function sales(int $count, string $title = self::PHONE, array $fields = []): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->listing($fields + [
                'title' => $title, 'status' => 'sold', 'finalized' => 1, 'final_price' => 300,
                'end_time' => gmdate('Y-m-d H:i:s', time() - 7200),
            ]);
        }
        return $ids;
    }

    private function change(int $id, array $fields): void
    {
        $this->db->update($this->table(Listing::TABLE), $fields, ['id = ?' => $id]);
    }

    private function opportunity(int $id): array|false
    {
        return $this->db->fetchRow($this->db->select()->from($this->table(Rows::TABLE))->where('listing_id = ?', $id));
    }

    private function comp(string $title, string $grade = 'B'): array|false
    {
        return $this->db->fetchRow($this->db->select()->from($this->table(CompsIndex::TABLE))
            ->where('model_key = ?', $this->modelKey($title))->where('condition_grade = ?', $grade));
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }

    private function setWorkerConfig(string $path, string $value): void
    {
        $this->db->insertOnDuplicate($this->table('core_config_data'), [
            'path' => $path, 'scope' => 'default', 'scope_id' => 0, 'value' => $value,
        ], ['value']);
    }
}
