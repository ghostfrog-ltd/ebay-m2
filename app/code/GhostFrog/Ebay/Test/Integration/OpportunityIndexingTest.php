<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Test\Integration;

use GhostFrog\Ebay\Cron\RefreshComps;
use GhostFrog\Ebay\Model\Api\Usage;
use GhostFrog\Ebay\Model\Collection\BrowseClient;
use GhostFrog\Ebay\Model\Comps\Index as CompsIndex;
use GhostFrog\Ebay\Model\FinalPrice\Queue;
use GhostFrog\Ebay\Model\FinalPrice\Worker;
use GhostFrog\Ebay\Model\ModelKey\Classifier;
use GhostFrog\Ebay\Model\Notification\Queue as NotificationQueue;
use GhostFrog\Ebay\Model\Notification\Slack;
use GhostFrog\Ebay\Model\Notification\Worker as NotificationWorker;
use GhostFrog\Ebay\Model\Opportunities\Index;
use GhostFrog\Ebay\Model\Opportunities\Rows;
use GhostFrog\Ebay\Model\PriceRefresh\Queue as PriceQueue;
use GhostFrog\Ebay\Model\PriceRefresh\Worker as RefreshWorker;
use GhostFrog\Ebay\Model\ResourceModel\Listing;
use Magento\Backend\Model\Auth\Session;
use Magento\Config\Model\Config;
use Magento\Cron\Model\ConfigInterface as CronConfig;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\ObjectManager\ConfigLoader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\ResourceConnection\ConnectionFactory;
use Magento\Framework\App\State;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
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
        $this->db->delete($this->table('ghostfrog_ebay_auction_price_history'));
        $this->db->delete($this->table('ghostfrog_ebay_api_quota'));
        $this->db->delete($this->table('ghostfrog_ebay_api_usage'));
        $this->db->delete($this->table(Listing::TABLE));
        $this->db->delete($this->table('core_config_data'), ['path IN (?)' => [
            'ghostfrog_ebay/general/enabled', 'ghostfrog_ebay/heartbeat/gf_heartbeat_enable_close',
            'ghostfrog_ebay/ebay_api/gf_allow_ebay_api', 'ghostfrog_ebay/comps/target_profit',
            'ghostfrog_ebay/ebay_api/ebay_app_id', 'ghostfrog_ebay/ebay_api/ebay_cert_id',
            'ghostfrog_ebay/slack/slack_webhook_url', 'ghostfrog_ebay/opportunity_monitoring/notifications_enabled',
            'ghostfrog_ebay/opportunity_monitoring/refresh_batch', 'ghostfrog_ebay/opportunity_monitoring/auction_60_minutes',
        ]]);
        $cache = self::$objects->get(CacheInterface::class);
        foreach (['ghostfrog_ebay_price_refresh_pause', 'ghostfrog_ebay_opportunity_slack_throttle',
            'ghostfrog_ebay_browse_token_' . hash('sha256', 'issue9-fixture-app')] as $key) {
            $cache->remove($key);
        }
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

    public function testOnlyDueIndexedListingsAreQueuedWithUrgentAuctionPriority(): void
    {
        $this->sales(5);
        $now = time();
        $recent = ['last_price_checked_at' => $this->date($now - 125)];
        $near = $this->listing($recent + ['end_time' => $this->date($now + 500)]);
        $bin = $this->listing(['sale_type' => 'bin', 'end_time' => null, 'last_seen_at' => $this->date($now - 3600)]);
        $both = $this->listing($recent + ['sale_type' => 'both', 'end_time' => $this->date($now + 570)]);
        $hour = $this->listing(['end_time' => $this->date($now + 1800), 'last_price_checked_at' => $this->date($now - 310)]);
        $day = $this->listing(['end_time' => $this->date($now + 7200), 'last_price_checked_at' => $this->date($now - 1810)]);
        $notYet = $this->listing($recent + ['end_time' => $this->date($now + 1800)]);
        $this->listing($recent + ['end_time' => $this->date($now + 7200)]);
        $this->listing(['end_time' => $this->date($now + 90000)]);
        $this->listing(['end_time' => $this->date($now - 60)]);
        $this->listing(['sale_type' => 'bin', 'end_time' => null, 'price_current' => 400]);
        $this->updateViews();

        $queue = self::$objects->get(PriceQueue::class);
        self::assertCount(1, $this->cronJobs());
        self::assertSame('* * * * *', $this->cronJobs()['ghostfrog_ebay_monitor_opportunities']['schedule']);
        self::assertSame(2, $queue->enqueueDue(2, 1));
        self::assertSame([$near, $bin], array_map('intval', array_column($queue->due(2, 1), 'listing_id')));
        self::assertSame(3, $queue->enqueueDue(10, 1));
        self::assertSame(0, $queue->enqueueDue(10, 1));
        self::assertSame($near, (int)$queue->due(1, 1)[0]['listing_id']);
        self::assertSame($bin, (int)$queue->due(1, 4)[0]['listing_id']);
        $ids = array_map('intval', array_column($queue->due(10, 1), 'listing_id'));
        self::assertEqualsCanonicalizing([$near, $bin, $both, $hour, $day], $ids);

        $this->setMonitoringConfig(['auction_60_minutes' => '2']);
        self::assertSame(1, $queue->enqueueDue(10, 1));
        self::assertNotFalse($this->stored(PriceQueue::TABLE, 'listing_id', $notYet));

        $this->change($near, ['end_time' => $this->date($now - 1)]);
        self::assertSame(1, $queue->discardObsolete(10));
        self::assertNotContains($near, array_map('intval', array_column($queue->due(10, 1), 'listing_id')));
    }

    public function testCombinedRefreshPreservesEvidenceAndReindexesOnlyItsListing(): void
    {
        $this->sales(5);
        $external = 'v1|950000001337|9001';
        $evidence = '{"Model":"SM-G991B","Trading condition":"Used"}';
        $id = $this->listing(['external_id' => $external, 'sale_type' => 'both', 'raw_attrs' => $evidence]);
        $other = $this->listing();
        $this->updateViews();
        $previousOther = $this->opportunity($other);
        $this->change($other, ['price_current' => '105.00']);
        $item = [
            'itemId' => $external, 'buyingOptions' => ['FIXED_PRICE', 'AUCTION'],
            'price' => ['value' => '140.29', 'currency' => 'GBP'],
            'currentBidPrice' => ['value' => '102.13', 'currency' => 'GBP'],
            'bidCount' => 4, 'itemEndDate' => gmdate(DATE_ATOM, time() + 3700),
        ];
        $worker = $this->browseWorker(function (string $url) use (&$item): array {
            self::assertStringEndsWith('/item/v1%7C950000001337%7C9001', $url);
            return [200, $item, []];
        });
        $this->setMonitoringConfig(['refresh_batch' => '1']);
        $worker->execute();

        $row = $this->stored(Listing::TABLE, 'id', $id);
        self::assertSame('102.13', $row['price_current']);
        self::assertSame('102.13', $row['price_bid_current']);
        self::assertSame('140.29', $row['price_bin_current']);
        self::assertSame(4, (int)$row['bids_count']);
        self::assertSame($row['last_seen_at'], $row['last_price_checked_at']);
        self::assertNotNull($row['last_price_checked_at']);
        self::assertSame(json_decode($evidence, true), json_decode($row['raw_attrs'], true));
        self::assertSame($this->modelKey(self::PHONE), $row['model_key']);
        self::assertSame('B', $row['condition_grade']);
        self::assertSame('102.13', $this->opportunity($id)['current_price']);
        self::assertSame('140.29', $this->opportunity($id)['bin_price']);
        self::assertSame($previousOther, $this->opportunity($other));
        $history = $this->stored('ghostfrog_ebay_auction_price_history', 'external_id', $external);
        self::assertSame('102.13', $history['price']);
        self::assertSame('140.29', $history['bin_price']);

        $item['price']['value'] = '220';
        $item['currentBidPrice']['value'] = '210';
        self::$objects->get(PriceQueue::class)->request($id);
        $worker->execute();
        self::assertFalse($this->opportunity($id));
        self::assertSame($previousOther, $this->opportunity($other));
    }

    public function testConcurrentObservationAndDbFailureLeaveNoPartialPriceOrAlert(): void
    {
        $this->sales(5);
        $id = $this->listing(['sale_type' => 'bin', 'end_time' => null]);
        $external = $this->stored(Listing::TABLE, 'id', $id)['external_id'];
        $this->updateViews();
        $this->enableAlerts();
        $overlap = true;
        $worker = $this->browseWorker(function () use ($id, $external, &$overlap): array {
            if ($overlap) {
                $this->change($id, ['price_current' => '125.00', 'last_seen_at' => $this->date(time())]);
            }
            return [200, $this->binItem($external, '110.00'), []];
        });
        $worker->execute();
        self::assertSame('125.00', $this->stored(Listing::TABLE, 'id', $id)['price_current']);
        self::assertFalse($this->stored('ghostfrog_ebay_auction_price_history', 'external_id', $external));
        self::assertFalse($this->stored(NotificationQueue::TABLE, 'listing_id', $id));

        $overlap = false;
        $trigger = $this->db->quoteIdentifier($this->table('gf_test_alert_write_failure'));
        $table = $this->db->quoteIdentifier($this->table(NotificationQueue::TABLE));
        $this->db->query("CREATE TRIGGER $trigger BEFORE INSERT ON $table FOR EACH ROW "
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture alert write failure'");
        try {
            $worker->execute();
            $row = $this->stored(Listing::TABLE, 'id', $id);
            self::assertSame('125.00', $row['price_current']);
            self::assertNull($row['last_price_checked_at']);
            self::assertFalse($this->stored('ghostfrog_ebay_auction_price_history', 'external_id', $external));
            self::assertSame('100.00', $this->opportunity($id)['current_price']);
            self::assertSame(1, (int)$this->stored(PriceQueue::TABLE, 'listing_id', $id)['attempts']);
        } finally {
            $this->db->query("DROP TRIGGER $trigger");
        }

        $this->makeDue(PriceQueue::TABLE, $id);
        $worker->execute();
        self::assertSame('110.00', $this->opportunity($id)['current_price']);
        self::assertSame('pending', $this->stored(NotificationQueue::TABLE, 'listing_id', $id)['status']);
        self::assertFalse($this->stored(PriceQueue::TABLE, 'listing_id', $id));
    }

    public function testBrowseReserveThrottleBackoffAndMissingListingRecovery(): void
    {
        $this->sales(5);
        $id = $this->listing(['sale_type' => 'bin', 'end_time' => null]);
        $this->updateViews();
        $calls = 0;
        $status = 429;
        $worker = $this->browseWorker(function (string $url) use (&$calls, &$status): array {
            ++$calls;
            self::assertStringContainsString('/item/get_item_by_legacy_id?legacy_item_id=', $url);
            return [$status, [], ['Retry-After' => '120']];
        }, 20);
        $this->enableAlerts();
        $worker->execute();
        self::assertSame(0, $calls);
        self::assertSame(0, (int)$this->stored(PriceQueue::TABLE, 'listing_id', $id)['attempts']);
        $usage = self::$objects->get(Usage::class);
        $usage->reserve(Usage::CATEGORY_SEARCH);
        self::assertSame(19, $usage->remaining(Usage::BROWSE));

        $this->seedBrowseQuota(100);
        $cache = self::$objects->get(CacheInterface::class);
        $cache->remove('ghostfrog_ebay_price_refresh_pause');
        $worker->execute();
        self::assertSame(1, $calls);
        self::assertGreaterThanOrEqual(time() + 118, $usage->quota(Usage::BROWSE)['retry_at']);
        self::assertSame(0, (int)$this->stored(PriceQueue::TABLE, 'listing_id', $id)['attempts']);
        $worker->execute();
        self::assertSame(1, $calls);
        self::assertFalse($this->stored(NotificationQueue::TABLE, 'listing_id', $id));

        $this->db->update($this->table('ghostfrog_ebay_api_quota'), ['blocked_until' => 0], ['bucket = ?' => Usage::BROWSE]);
        $cache->remove('ghostfrog_ebay_price_refresh_pause');
        $status = 503;
        $worker->execute();
        self::assertSame(2, $calls);
        self::assertSame(1, (int)$this->stored(PriceQueue::TABLE, 'listing_id', $id)['attempts']);
        $worker->execute();
        self::assertSame(2, $calls);

        $status = 404;
        $this->makeDue(PriceQueue::TABLE, $id);
        $worker->execute();
        self::assertSame(3, $calls);
        $row = $this->stored(Listing::TABLE, 'id', $id);
        self::assertSame('unknown', $row['status']);
        self::assertNull($row['final_price']);
        self::assertNull($row['last_price_checked_at']);
        self::assertFalse($this->opportunity($id));
        self::assertFalse($this->stored(PriceQueue::TABLE, 'listing_id', $id));
    }

    public function testBinDeliveryDeduplicatesAndRevalidatesThePurchasePrice(): void
    {
        $this->sales(5);
        $id = $this->listing(['sale_type' => 'bin', 'end_time' => null]);
        $external = $this->stored(Listing::TABLE, 'id', $id)['external_id'];
        $this->updateViews();
        $price = '111.09';
        $refresh = $this->browseWorker(fn (): array => [200, $this->binItem($external, $price), []]);
        $this->setMonitoringConfig(['notifications_enabled' => '1']);
        $refresh->execute();
        self::assertFalse($this->stored(NotificationQueue::TABLE, 'listing_id', $id));

        $this->enableAlerts();
        $queue = self::$objects->get(PriceQueue::class);
        $queue->request($id);
        $refresh->execute();
        $calls = 0;
        $alerts = $this->alertWorker(function (array $payload) use (&$calls, $external): array {
            ++$calls;
            foreach ([self::PHONE, "/itm/$external", 'Buy It Now', '£111.09', '£194.00',
                '£132.91', 'Comparable sales 5', 'Last price checked', 'End time'] as $field) {
                self::assertStringContainsString($field, $payload['text']);
            }
            return [200, []];
        });
        $alerts->execute();
        self::assertSame(1, $calls);
        $this->assertPriceFreshness($id, 'Fresh');
        $queue->request($id);
        $refresh->execute();
        $alerts->execute();
        self::assertSame(1, $calls);

        $this->change($id, ['price_current' => '250.00', 'price_bin_current' => '250.00']);
        self::$objects->get(NotificationQueue::class)->observe($id);
        $this->change($id, ['price_current' => '100.00']);
        $queue->request($id);
        $refresh->execute();
        self::assertSame('pending', $this->stored(NotificationQueue::TABLE, 'listing_id', $id)['status']);

        $this->change($id, ['price_current' => '250.00', 'price_bin_current' => '250.00']);
        $alerts->execute();
        self::assertSame(1, $calls);
        self::assertSame('inactive', $this->stored(NotificationQueue::TABLE, 'listing_id', $id)['status']);
    }

    public function testAuctionAlertsWaitForFreshnessHonourRetriesAndSuppressExpiry(): void
    {
        $this->sales(5);
        $now = time();
        $end = $now + 240;
        $id = $this->listing(['end_time' => $this->date($end)]);
        $external = $this->stored(Listing::TABLE, 'id', $id)['external_id'];
        $this->updateViews();
        $this->enableAlerts();
        $refresh = $this->browseWorker(static fn (): array => [200, [
            'itemId' => "v1|$external|0", 'buyingOptions' => ['AUCTION'],
            'currentBidPrice' => ['value' => '105.00', 'currency' => 'GBP'],
            'bidCount' => 2, 'itemEndDate' => gmdate(DATE_ATOM, $end),
        ], []]);
        $refresh->execute();
        $calls = 0;
        $status = 429;
        $alerts = $this->alertWorker(function (array $payload) use (&$calls, &$status): array {
            ++$calls;
            self::assertStringContainsString('Auction (indicative', $payload['text']);
            return [$status, ['retry-after' => '90']];
        });
        $this->change($id, ['last_price_checked_at' => $this->date($now - 100), 'last_seen_at' => $this->date($now - 100)]);
        $alerts->execute();
        self::assertSame(0, $calls);
        self::assertNotFalse($this->stored(PriceQueue::TABLE, 'listing_id', $id));

        $refresh->execute();
        $this->makeDue(NotificationQueue::TABLE, $id);
        $alerts->execute();
        self::assertSame(1, $calls);
        $job = $this->stored(NotificationQueue::TABLE, 'listing_id', $id);
        self::assertSame('pending', $job['status']);
        self::assertSame(1, (int)$job['attempts']);
        self::assertGreaterThanOrEqual($this->date(time() + 88), $job['available_at']);
        $alerts->execute();
        self::assertSame(1, $calls);

        self::$objects->get(CacheInterface::class)->remove('ghostfrog_ebay_opportunity_slack_throttle');
        $this->makeDue(NotificationQueue::TABLE, $id);
        $status = 200;
        $alerts->execute();
        self::assertSame(2, $calls);
        self::assertSame('sent', $this->stored(NotificationQueue::TABLE, 'listing_id', $id)['status']);

        $table = $this->table(NotificationQueue::TABLE);
        $this->db->update($table, ['status' => 'sending'], ['listing_id = ?' => $id]);
        $alerts->execute();
        self::assertSame(2, $calls);
        self::assertSame('uncertain', $this->stored(NotificationQueue::TABLE, 'listing_id', $id)['status']);

        $this->db->update($table, ['status' => 'pending'], ['listing_id = ?' => $id]);
        $this->change($id, ['end_time' => $this->date(time() - 1)]);
        $alerts->execute();
        self::assertSame(2, $calls);
        self::assertSame('inactive', $this->stored(NotificationQueue::TABLE, 'listing_id', $id)['status']);
        $this->db->query('SET timestamp = ' . ($end + 1));
        self::$objects->get(Index::class)->expire();
        self::assertFalse($this->opportunity($id));
    }

    public function testOverlappingWorkerRespectsTheDatabaseLock(): void
    {
        $this->sales(5);
        $this->listing(['sale_type' => 'bin', 'end_time' => null]);
        $this->updateViews();
        $worker = $this->browseWorker(static fn (): array => self::fail('An overlapping worker called Browse.'));
        $other = self::$objects->get(ConnectionFactory::class)->create($this->db->getConfig());
        $lock = $this->db->fetchOne('SELECT DATABASE()') . '|ghostfrog_ebay_price_refresh';
        self::assertSame(1, (int)$other->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]));
        try {
            $worker->execute();
            self::assertSame(0, (int)self::$objects->get(PriceQueue::class)->metrics()['depth']);
        } finally {
            $other->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
            $other->closeConnection();
        }
    }

    private function cronJobs(): array
    {
        return self::$objects->get(CronConfig::class)->getJobs()['ghostfrog_ebay_monitoring'];
    }

    private function assertPriceFreshness(int $id, string $expected): void
    {
        $admin = self::$objects->get(UserFactory::class)->create()->getCollection()->getFirstItem();
        self::$objects->get(Session::class)->setUser($admin);
        $grid = $this->grid();
        $filter = self::$objects->create(FilterBuilder::class)->setField('listing_id')->setValue($id)->create();
        $grid->getContext()->getDataProvider()->addFilter($filter);
        $data = json_decode($grid->render('json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertNotEmpty($data['items'][0]['last_price_checked_at']);
        self::assertSame($expected, $data['items'][0]['price_freshness']);
    }

    private function browseWorker(callable $response, int $remaining = 100): RefreshWorker
    {
        $encryptor = self::$objects->get(EncryptorInterface::class);
        $this->setWorkerConfig('ghostfrog_ebay/general/enabled', '1');
        $this->setWorkerConfig('ghostfrog_ebay/ebay_api/gf_allow_ebay_api', '1');
        $this->setWorkerConfig('ghostfrog_ebay/ebay_api/ebay_app_id', 'issue9-fixture-app');
        $this->setWorkerConfig('ghostfrog_ebay/ebay_api/ebay_cert_id', $encryptor->encrypt('fixture-cert'));
        self::$objects->get(ReinitableConfigInterface::class)->reinit();
        self::$objects->get(CacheInterface::class)->save(
            $encryptor->encrypt('fixture-token'),
            'ghostfrog_ebay_browse_token_' . hash('sha256', 'issue9-fixture-app')
        );
        $this->seedBrowseQuota($remaining);
        $status = 0;
        $body = [];
        $headers = [];
        $curl = $this->createMock(Curl::class);
        $curl->method('get')->willReturnCallback(function (string $url) use ($response, &$status, &$body, &$headers): void {
            [$status, $body, $headers] = $response($url);
        });
        $curl->expects(self::never())->method('post');
        $curl->method('getStatus')->willReturnCallback(static function () use (&$status): int { return $status; });
        $curl->method('getBody')->willReturnCallback(static function () use (&$body): string { return json_encode($body); });
        $curl->method('getHeaders')->willReturnCallback(static function () use (&$headers): array { return $headers; });
        $factory = $this->createMock(CurlFactory::class);
        $factory->method('create')->willReturn($curl);
        $browse = self::$objects->create(BrowseClient::class, ['curlFactory' => $factory]);
        return self::$objects->create(RefreshWorker::class, ['browse' => $browse]);
    }

    private function alertWorker(callable $response): NotificationWorker
    {
        $status = 0;
        $headers = [];
        $curl = $this->createMock(Curl::class);
        $curl->method('post')->willReturnCallback(function (string $url, string $body) use ($response, &$status, &$headers): void {
            self::assertSame('https://hooks.slack.com/services/FIXTURE/CHANNEL/TEST', $url);
            [$status, $headers] = $response(json_decode($body, true, flags: JSON_THROW_ON_ERROR));
        });
        $curl->method('getStatus')->willReturnCallback(static function () use (&$status): int { return $status; });
        $curl->method('getHeaders')->willReturnCallback(static function () use (&$headers): array { return $headers; });
        $factory = $this->createMock(CurlFactory::class);
        $factory->method('create')->willReturn($curl);
        $slack = self::$objects->create(Slack::class, ['clients' => $factory]);
        return self::$objects->create(NotificationWorker::class, ['slack' => $slack]);
    }

    private function seedBrowseQuota(int $remaining): void
    {
        $this->db->delete($this->table('ghostfrog_ebay_api_quota'));
        $this->db->insert($this->table('ghostfrog_ebay_api_quota'), ['bucket' => Usage::ANALYTICS]);
        $now = time();
        $rate = ['limit' => 100, 'remaining' => $remaining, 'reported_remaining' => $remaining, 'reset_at' => $now + 300];
        self::$objects->get(Usage::class)->saveDailyRates([Usage::BROWSE => $rate, Usage::TRADING => $rate], $now);
    }

    private function enableAlerts(): void
    {
        $this->setWorkerConfig('ghostfrog_ebay/general/enabled', '1');
        $this->setWorkerConfig('ghostfrog_ebay/slack/slack_webhook_url', self::$objects->get(EncryptorInterface::class)
            ->encrypt('https://hooks.slack.com/services/FIXTURE/CHANNEL/TEST'));
        $this->setMonitoringConfig(['notifications_enabled' => '1']);
    }

    private function setMonitoringConfig(array $fields): void
    {
        foreach ($fields as $key => $value) {
            $this->setWorkerConfig('ghostfrog_ebay/opportunity_monitoring/' . $key, $value);
        }
        self::$objects->get(ReinitableConfigInterface::class)->reinit();
    }

    private function binItem(string $id, string $price): array
    {
        return [
            'itemId' => "v1|$id|0", 'buyingOptions' => ['FIXED_PRICE'],
            'price' => ['value' => $price, 'currency' => 'GBP'],
            'estimatedAvailabilities' => [['estimatedAvailabilityStatus' => 'IN_STOCK']],
        ];
    }

    private function makeDue(string $table, int $id): void
    {
        $this->db->update($this->table($table), ['available_at' => $this->date(time() - 1)], ['listing_id = ?' => $id]);
    }

    private function stored(string $table, string $field, int|string $id): array|false
    {
        return $this->db->fetchRow($this->db->select()->from($this->table($table))->where("$field = ?", $id));
    }

    private function date(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
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
