<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Test\Unit\Model\Collection;

use DateTimeImmutable;
use DateTimeZone;
use GhostFrog\Ebay\Model\Collection\SearchPaging;
use PHPUnit\Framework\TestCase;

final class SearchPagingTest extends TestCase
{
    private const NOW = '2026-11-26 12:00:00';
    private const LAST_RUN = '2026-11-26 11:00:00';

    public function testBinBoundaryIsThePreviousRunLessTheOverlap(): void
    {
        $boundary = SearchPaging::boundary('bin', $this->at(self::NOW), $this->at(self::LAST_RUN), 3600);

        self::assertEquals($this->at('2026-11-26 10:45:00'), $boundary);
    }

    public function testBinWithoutAPreviousRunReadsOnePage(): void
    {
        $boundary = SearchPaging::boundary('bin', $this->at(self::NOW), null, 3600);

        self::assertNull($boundary);
        self::assertSame(
            SearchPaging::FIRST_RUN,
            SearchPaging::stopReason('bin', [$this->created('11:59')], $boundary, true, 1, 4)
        );
    }

    public function testAuctionBoundaryIsTheNextRunPlusTheOverlap(): void
    {
        $boundary = SearchPaging::boundary('auction', $this->at(self::NOW), null, 3600);

        self::assertEquals($this->at('2026-11-26 13:15:00'), $boundary);
    }

    public function testBinKeepsPagingWhileEveryListingIsNewerThanThePreviousRun(): void
    {
        $page = [$this->created('11:58'), $this->created('11:30'), $this->created('10:50')];

        self::assertNull(SearchPaging::stopReason('bin', $page, $this->binBoundary(), true, 1, 4));
    }

    public function testBinStopsOnceAPageReachesListingsThePreviousRunCovered(): void
    {
        $page = [$this->created('11:58'), $this->created('10:44')];

        self::assertSame(
            SearchPaging::CAUGHT_UP,
            SearchPaging::stopReason('bin', $page, $this->binBoundary(), true, 1, 4)
        );
    }

    public function testAuctionKeepsPagingUntilAuctionsEndAfterTheNextRun(): void
    {
        $boundary = SearchPaging::boundary('auction', $this->at(self::NOW), null, 3600);

        self::assertNull(SearchPaging::stopReason(
            'auction',
            [$this->ending('12:05'), $this->ending('13:10')],
            $boundary,
            true,
            1,
            4
        ));
        self::assertSame(SearchPaging::CAUGHT_UP, SearchPaging::stopReason(
            'auction',
            [$this->ending('13:10'), $this->ending('13:20')],
            $boundary,
            true,
            2,
            4
        ));
    }

    public function testSearchStopsAtTheLastPageOrTheCap(): void
    {
        $page = [$this->created('11:58')];

        self::assertSame(
            SearchPaging::EXHAUSTED,
            SearchPaging::stopReason('bin', $page, $this->binBoundary(), false, 1, 4)
        );
        self::assertSame(
            SearchPaging::EXHAUSTED,
            SearchPaging::stopReason('bin', [], $this->binBoundary(), true, 1, 4)
        );
        self::assertSame(
            SearchPaging::CAPPED,
            SearchPaging::stopReason('bin', $page, $this->binBoundary(), true, 4, 4)
        );
    }

    public function testMissingOrUnreadableDatesStopAfterThePage(): void
    {
        $page = [$this->created('11:58'), ['itemId' => 'v1|2|0']];

        self::assertSame(
            SearchPaging::NO_DATES,
            SearchPaging::stopReason('bin', $page, $this->binBoundary(), true, 1, 4)
        );
        self::assertSame(
            SearchPaging::NO_DATES,
            SearchPaging::stopReason('auction', [['itemEndDate' => 'soon']], $this->binBoundary(), true, 1, 4)
        );
    }

    public function testRepeatsCountBinListingsCreatedBeforeThePreviousRun(): void
    {
        $page = [$this->created('11:58'), $this->created('11:00'), $this->created('09:00')];

        self::assertSame(2, SearchPaging::repeats('bin', $page, $this->at(self::LAST_RUN)));
        self::assertSame(0, SearchPaging::repeats('bin', $page, null));
        self::assertSame(0, SearchPaging::repeats('auction', [$this->ending('12:05')], $this->at(self::LAST_RUN)));
    }

    private function binBoundary(): DateTimeImmutable
    {
        return SearchPaging::boundary('bin', $this->at(self::NOW), $this->at(self::LAST_RUN), 3600);
    }

    private function created(string $time): array
    {
        return ['itemCreationDate' => '2026-11-26T' . $time . ':00.000Z'];
    }

    private function ending(string $time): array
    {
        return ['itemEndDate' => '2026-11-26T' . $time . ':00.000Z'];
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }
}
