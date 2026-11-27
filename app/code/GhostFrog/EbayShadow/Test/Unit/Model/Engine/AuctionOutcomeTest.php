<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Test\Unit\Model\Engine;

use GhostFrog\EbayShadow\Model\Engine\AuctionOutcome;
use GhostFrog\EbayShadow\Model\Engine\BidIncrements;
use PHPUnit\Framework\TestCase;

final class AuctionOutcomeTest extends TestCase
{
    private const END = '2026-11-20 20:00:00';

    private static function outcome(string $status, ?float $final, ?int $bids = 3, ?string $reason = null,
        ?float $current = 50.0): array
    {
        return ['status' => $status, 'final_price' => $final, 'bids_count' => $bids, 'outcome_reason' => $reason,
            'price_current' => $current];
    }

    private static function now(int $days = 1): int
    {
        return strtotime(self::END . ' UTC') + $days * 86400;
    }

    public function testFinalAboveMaxBidIsLost(): void
    {
        $result = AuctionOutcome::resolve(100.0, self::outcome('sold', 120.0), self::END, self::now(), 7);
        self::assertSame('lost', $result['result']);
        self::assertSame(120.0, $result['final_price']);
    }

    public function testWonAtOneIncrementAboveTheFinalPrice(): void
    {
        $result = AuctionOutcome::resolve(100.0, self::outcome('sold', 80.0), self::END, self::now(), 7);
        self::assertSame('won', $result['result']);
        self::assertSame(82.0, $result['price']);
    }

    public function testWinningPriceIsCappedAtTheMaxBid(): void
    {
        $result = AuctionOutcome::resolve(80.5, self::outcome('sold', 80.0), self::END, self::now(), 7);
        self::assertSame(80.5, $result['price']);
    }

    public function testNoBidsMeansWonUnopposedAtTheStartingPrice(): void
    {
        $won = AuctionOutcome::resolve(60.0, self::outcome('ended', null, 0), self::END, self::now(), 7);
        self::assertSame(['won', 'won_unopposed', 50.0], [$won['result'], $won['reason'], $won['price']]);
        $lost = AuctionOutcome::resolve(40.0, self::outcome('ended', null, 0), self::END, self::now(), 7);
        self::assertSame('lost', $lost['result']);
    }

    public function testReserveNotMetIsLostAndAnUnverifiedPriceIsVoid(): void
    {
        $reserve = AuctionOutcome::resolve(60.0, self::outcome('ended', null, 4, 'reserve_not_met'), self::END, self::now(), 7);
        self::assertSame('lost', $reserve['result']);
        $void = AuctionOutcome::resolve(60.0, self::outcome('ended', null, 4, 'ended_price_unverified'), self::END, self::now(), 7);
        self::assertSame(['void', 'price_unverified'], [$void['result'], $void['reason']]);
    }

    public function testWaitsForAnOutcomeThenVoidsAfterTheLimit(): void
    {
        self::assertSame('pending', AuctionOutcome::resolve(60.0, self::outcome('live', null), self::END, self::now(2), 7)['result']);
        self::assertSame('void', AuctionOutcome::resolve(60.0, self::outcome('live', null), self::END, self::now(8), 7)['result']);
        self::assertSame('void', AuctionOutcome::resolve(60.0, null, self::END, self::now(), 7)['result']);
    }

    public function testEbayUkIncrements(): void
    {
        self::assertSame(0.05, BidIncrements::at(0.99));
        self::assertSame(0.20, BidIncrements::at(5.00));
        self::assertSame(0.50, BidIncrements::at(5.01));
        self::assertSame(1.00, BidIncrements::at(60.00));
        self::assertSame(2.00, BidIncrements::at(149.99));
        self::assertSame(5.00, BidIncrements::at(300.00));
        self::assertSame(10.00, BidIncrements::at(599.00));
        self::assertSame(20.00, BidIncrements::at(1500.00));
        self::assertSame(50.00, BidIncrements::at(2999.00));
        self::assertSame(100.00, BidIncrements::at(3000.01));
        self::assertSame(102.0, BidIncrements::next(100.0));
    }
}
