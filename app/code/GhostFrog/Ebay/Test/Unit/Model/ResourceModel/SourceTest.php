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

    public function testMotomineIsCollectedForPriceHistoryOnly(): void
    {
        self::assertSame(['motomine'], \GhostFrog\Ebay\Model\Collection\SourceSearches::collectOnly());
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

    public function testMinimumsAndColourAreNormalised(): void
    {
        self::assertNull(Source::minimum(' ', 'min_profit'));
        self::assertSame('20.00', Source::minimum('20', 'min_profit'));
        self::assertSame('16.50', Source::minimum('16.5', 'shipping'));
        self::assertNull(Source::minimum('', 'shipping'));
        self::assertSame('12.50', Source::minimum(' 12.5 ', 'min_roi_percent'));
        self::assertNull(Source::colour(''));
        self::assertSame('#1a73e8', Source::colour('#1A73E8'));
    }

    #[DataProvider('invalidSourceValues')]
    public function testInvalidMinimumsAndColoursAreRejected(callable $parse): void
    {
        $this->expectException(LocalizedException::class);
        $parse();
    }

    public static function invalidSourceValues(): array
    {
        return [
            'negative profit' => [static fn() => Source::minimum('-1', 'min_profit')],
            'three decimals' => [static fn() => Source::minimum('1.005', 'min_profit')],
            'roi over 1000' => [static fn() => Source::minimum('1001', 'min_roi_percent')],
            'short hex' => [static fn() => Source::colour('#fff')],
            'named colour' => [static fn() => Source::colour('red')],
        ];
    }

    public function testChangingAMinimumWritesItAndInvalidatesOpportunities(): void
    {
        $row = $this->row(['min_profit' => null, 'min_roi_percent' => '20.00', 'colour' => null]);
        $db = $this->connection($row);
        $db->expects(self::once())->method('update')->with(
            'ghostfrog_ebay_sources',
            self::callback(static fn(array $values): bool => $values['min_profit'] === '25.00'
                && !array_key_exists('min_roi_percent', $values) && !array_key_exists('colour', $values)),
            ['id = ?' => 6]
        );
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->expects(self::once())->method('invalidate');
        $indexers = $this->createMock(IndexerRegistry::class);
        $indexers->method('get')->willReturn($indexer);

        $source = $this->source($db, $indexers);
        $source->save(6, [
            'enabled' => '1', 'interval_minutes' => '60',
            'min_profit' => '25', 'min_roi_percent' => '20', 'colour' => '',
        ], $source->revision($row));
    }

    public function testMinCompsIsAWholeNumberInRange(): void
    {
        self::assertSame(15, Source::minComps(' 15 '));
        foreach (['0', '1001', '2.5', '', 'abc'] as $invalid) {
            try {
                Source::minComps($invalid);
                self::fail("Accepted $invalid");
            } catch (LocalizedException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testChangingMinCompsInvalidatesOpportunities(): void
    {
        $row = $this->row(['min_comps' => '15']);
        $db = $this->connection($row);
        $db->expects(self::once())->method('update')->with(
            'ghostfrog_ebay_sources',
            self::callback(static fn(array $values): bool => $values['min_comps'] === 8),
            ['id = ?' => 6]
        );
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->expects(self::once())->method('invalidate');
        $indexers = $this->createStub(IndexerRegistry::class);
        $indexers->method('get')->willReturn($indexer);

        $source = $this->source($db, $indexers);
        $source->save(6, ['enabled' => '1', 'interval_minutes' => '60', 'min_comps' => '8'], $source->revision($row));
    }

    public function testColourOnlyChangeLeavesOpportunitiesAlone(): void
    {
        $row = $this->row(['colour' => null]);
        $db = $this->connection($row);
        $db->expects(self::once())->method('update')->with(
            'ghostfrog_ebay_sources',
            self::callback(static fn(array $values): bool => $values['colour'] === '#ff6600'),
            ['id = ?' => 6]
        );
        $indexers = $this->createMock(IndexerRegistry::class);
        $indexers->expects(self::never())->method('get');

        $source = $this->source($db, $indexers);
        $source->save(6, ['enabled' => '1', 'interval_minutes' => '60', 'colour' => '#FF6600'], $source->revision($row));
    }

    public function testBlankMinimumClearsAnOverride(): void
    {
        $row = $this->row(['min_profit' => '20.00']);
        $db = $this->connection($row);
        $db->expects(self::once())->method('update')->with(
            'ghostfrog_ebay_sources',
            self::callback(static fn(array $values): bool => array_key_exists('min_profit', $values)
                && $values['min_profit'] === null),
            ['id = ?' => 6]
        );

        $indexers = $this->createStub(IndexerRegistry::class);
        $indexers->method('get')->willReturn($this->createStub(IndexerInterface::class));

        $source = $this->source($db, $indexers);
        $source->save(6, ['enabled' => '1', 'interval_minutes' => '60', 'min_profit' => ''], $source->revision($row));
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
