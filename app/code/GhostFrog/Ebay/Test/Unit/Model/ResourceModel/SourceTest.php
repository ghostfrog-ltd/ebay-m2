<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Test\Unit\Model\ResourceModel;

use GhostFrog\Ebay\Model\ResourceModel\Source;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourceTest extends TestCase
{
    public function testIntervalIsSubmittedInWholeMinutes(): void
    {
        self::assertSame(60, Source::intervalSeconds('1'));
        self::assertSame(3600, Source::intervalSeconds(' 60 '));
        self::assertSame(604800, Source::intervalSeconds('10080'));
    }

    #[DataProvider('invalidIntervals')]
    public function testInvalidIntervalsAreRejected(mixed $minutes): void
    {
        $this->expectException(LocalizedException::class);
        Source::intervalSeconds($minutes);
    }

    public static function invalidIntervals(): array
    {
        return [['0'], ['-5'], ['10081'], ['1.5'], ['abc'], [''], [null], [['60']]];
    }

    public function testSearchSummaryMatchesCodeDefinitions(): void
    {
        $apple = Source::search('ebay-apple');
        self::assertNull($apple['seller']);
        self::assertGreaterThan(0, $apple['categories']);
        self::assertGreaterThan(1, $apple['queries']);

        self::assertSame('motomine', Source::search('motomine')['seller']);
        self::assertNull(Source::search('ebay-motomine'));
        self::assertNull(Source::search(null));
    }

    public function testUnchangedSaveSkipsTheRevisionCheckAndWritesNothing(): void
    {
        $row = $this->row(['enabled' => '1', 'scrape_interval_seconds' => '3600']);
        $db = $this->connection($row);
        $db->expects(self::never())->method('update');
        $db->expects(self::once())->method('commit');

        $this->source($db)->save(6, ['enabled' => '1', 'interval_minutes' => '60'], 'stale');
    }

    public function testChangedSaveRejectsAStaleRevision(): void
    {
        $db = $this->connection($this->row(['enabled' => '1']));
        $db->expects(self::never())->method('update');
        $db->expects(self::once())->method('rollBack');

        $this->expectException(LocalizedException::class);
        $this->source($db)->save(6, ['enabled' => '0', 'interval_minutes' => '60'], 'stale');
    }

    public function testChangedSaveWritesOnlyTheChangedFields(): void
    {
        $row = $this->row(['enabled' => '1', 'scrape_interval_seconds' => '3600']);
        $db = $this->connection($row);
        $db->expects(self::once())->method('update')->with(
            'ghostfrog_ebay_sources',
            self::callback(static fn(array $values): bool => $values['enabled'] === 0
                && !array_key_exists('scrape_interval_seconds', $values)
                && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $values['updated_at_utc']) === 1),
            ['id = ?' => 6]
        );
        $db->expects(self::once())->method('commit');

        $source = $this->source($db);
        $source->save(6, ['enabled' => '0', 'interval_minutes' => '60'], $source->revision($row));
    }

    public function testDisablingRemovesTheSourceOpportunitiesWithoutAReindex(): void
    {
        $row = $this->row(['enabled' => '1']);
        $db = $this->connection($row);
        $db->expects(self::once())->method('update');
        $db->expects(self::once())->method('delete')->with('ghostfrog_ebay_opportunities', self::callback(
            static fn(array $where): bool => array_keys($where) === ['listing_id IN (?)']
        ));
        $indexers = $this->createMock(IndexerRegistry::class);
        $indexers->expects(self::never())->method('get');

        $source = $this->source($db, $indexers);
        $source->save(6, ['enabled' => '0', 'interval_minutes' => '60'], $source->revision($row));
    }

    public function testEnablingInvalidatesTheOpportunitiesIndexAfterCommit(): void
    {
        $row = $this->row(['enabled' => '0']);
        $db = $this->connection($row);
        $db->expects(self::once())->method('commit');
        $db->expects(self::never())->method('delete');
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->expects(self::once())->method('invalidate');
        $indexers = $this->createMock(IndexerRegistry::class);
        $indexers->expects(self::once())->method('get')->with('ghostfrog_ebay_opportunities')->willReturn($indexer);

        $source = $this->source($db, $indexers);
        $source->save(6, ['enabled' => '1', 'interval_minutes' => '60'], $source->revision($row));
    }

    public function testIntervalOnlyChangeLeavesOpportunitiesAlone(): void
    {
        $row = $this->row(['enabled' => '1']);
        $db = $this->connection($row);
        $db->expects(self::never())->method('delete');
        $indexers = $this->createMock(IndexerRegistry::class);
        $indexers->expects(self::never())->method('get');

        $source = $this->source($db, $indexers);
        $source->save(6, ['enabled' => '1', 'interval_minutes' => '30'], $source->revision($row));
    }

    public function testMassDisableAlwaysClearsOpportunities(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->expects(self::once())->method('where')->with('source_id IN (?)', [3, 9])->willReturnSelf();
        $db = $this->createMock(AdapterInterface::class);
        $db->method('select')->willReturn($select);
        $db->expects(self::once())->method('update')->with(
            'ghostfrog_ebay_sources',
            self::anything(),
            ['id IN (?)' => [3, 9], 'COALESCE(enabled, 0) <> ?' => 0]
        )->willReturn(0);
        $db->expects(self::once())->method('delete');
        $db->expects(self::once())->method('commit');
        $indexers = $this->createMock(IndexerRegistry::class);
        $indexers->expects(self::never())->method('get');

        self::assertSame(0, $this->source($db, $indexers)->setEnabled(['3', 'x', '9', '-1'], false));
    }

    public function testActiveListingConditionTreatsMissingOrNullSourcesConsistently(): void
    {
        $db = $this->createMock(AdapterInterface::class);
        $db->method('quoteIdentifier')->willReturnCallback(static fn(string $name): string => "`$name`");
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);

        self::assertSame(
            'NOT EXISTS (SELECT 1 FROM `ghostfrog_ebay_sources` AS gf_paused_source'
            . ' WHERE gf_paused_source.id = listing.source_id AND COALESCE(gf_paused_source.enabled, 0) <> 1)',
            Source::activeListing($resource, 'listing')
        );
    }

    public function testNullEnabledIsTreatedAsDisabled(): void
    {
        $db = $this->connection($this->row(['enabled' => null]));
        $db->expects(self::never())->method('update');

        $this->source($db)->save(6, ['enabled' => '0', 'interval_minutes' => '60'], 'stale');
    }

    public function testIncompleteFormIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->source($this->createMock(AdapterInterface::class))->save(6, ['enabled' => 'yes'], 'x');
    }

    private function row(array $values): array
    {
        return $values + [
            'id' => '6', 'name' => 'ebay-apple', 'domain' => 'ebay-apple', 'enabled' => '1',
            'scrape_interval_seconds' => '3600',
        ];
    }

    private function connection(array $row): AdapterInterface
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('forUpdate')->willReturnSelf();
        $db = $this->createMock(AdapterInterface::class);
        $db->method('select')->willReturn($select);
        $db->method('fetchRow')->willReturn($row);
        return $db;
    }

    private function source(AdapterInterface $db, ?IndexerRegistry $indexers = null): Source
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);
        return new Source($resource, $indexers ?? $this->createMock(IndexerRegistry::class));
    }
}
