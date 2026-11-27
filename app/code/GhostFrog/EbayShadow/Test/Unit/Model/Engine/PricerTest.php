<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Test\Unit\Model\Engine;

use GhostFrog\EbayShadow\Model\Engine\Pricer;
use GhostFrog\EbayShadow\Model\Engine\Statistics;
use PHPUnit\Framework\TestCase;

final class PricerTest extends TestCase
{
    private function pricer(array $settings = [], float $fee = 10.0): Pricer
    {
        // A flat £1 Buyer Protection fee keeps the arithmetic readable.
        return new Pricer(SettingsFixture::defaults($settings), static fn(float $price): float => 1.0, $fee, 4.0, 5.0, 5.0);
    }

    public function testCautiousProfitFollowsTheFormula(): void
    {
        $pricer = $this->pricer();
        // 200 x 0.9 x 0.95 = 171; fees 17.10; net = 171 - 17.10 - 5 postage - 2.50 packaging = 146.40.
        self::assertSame(171.0, $pricer->cautiousSellPrice(200.0));
        self::assertSame(146.4, $pricer->saleNet(171.0)['net']);
        // Buy cost = 100 + 1 Buyer Protection + 4 inbound postage = 105.
        self::assertSame(105.0, $pricer->buyCost(100.0));
        self::assertSame(41.4, $pricer->cautiousProfit(200.0, 100.0));
        self::assertSame(39.43, $pricer->roiPercent(41.4, 100.0));
    }

    public function testReturnsAndPerOrderFeesReduceTheNet(): void
    {
        $pricer = $this->pricer(['costs/returns_rate_percent' => 10.0, 'costs/per_order_fee' => 0.3]);
        $net = $pricer->saleNet(100.0);
        self::assertSame(10.3, $net['fees']);
        // 10% x (2 x 5 postage + 2.50 packaging) = 1.25.
        self::assertSame(1.25, $net['returns_cost']);
        self::assertSame(80.95, $net['net']);
    }

    public function testScoreIsProfitPerPoundPerDay(): void
    {
        self::assertSame(0.01, Pricer::score(40.0, 100.0, 40.0));
        self::assertSame(0.0, Pricer::score(40.0, 0.0, 40.0));
        // 2 + 2 inbound, 2 listing, 10 selling, 1 + 2 outbound, 2 payout.
        self::assertSame(21.0, Pricer::cycleDays(SettingsFixture::defaults(), 10.0));
    }

    public function testMaxBidIsTheHighestPriceMeetingProfitRoiAndCap(): void
    {
        $pricer = $this->pricer();
        $max = $pricer->maxBid(200.0);
        self::assertNotNull($max);
        // At the max bid both minimums hold; a penny more breaks one of them.
        $profit = $pricer->cautiousProfit(200.0, $max);
        self::assertGreaterThanOrEqual(10.0, $profit);
        self::assertGreaterThanOrEqual(10.0, $pricer->roiPercent($profit, $max));
        $over = $pricer->cautiousProfit(200.0, $max + 0.01);
        self::assertTrue($over < 10.0 || $pricer->roiPercent($over, $max + 0.01) < 10.0);

        $capped = $pricer->maxBid(200.0, 90.0);
        self::assertSame(85.0, $capped);
        self::assertNull($pricer->maxBid(10.0));
    }

    public function testDaysToSellComesFromRecentSalesRate(): void
    {
        self::assertSame(3.0, Pricer::daysToSell(10, 30, 30.0));
        self::assertSame(1.0, Pricer::daysToSell(90, 30, 30.0));
        self::assertSame(30.0, Pricer::daysToSell(0, 30, 30.0));
    }

    public function testOutboundPostageUsesBandOrCoreEstimate(): void
    {
        $settings = SettingsFixture::defaults();
        self::assertSame(3.5, Pricer::outboundPostage($settings, 'ebay-apple', 12.0, 3.0));
        self::assertSame(9.0, Pricer::outboundPostage($settings, 'ebay-consoles', 12.0, 3.0));
        self::assertSame(6.0, Pricer::outboundPostage($settings, 'ebay-consoles', 12.0, null));
        self::assertSame(6.0, Pricer::inboundPostage(12.0, null));
    }

    public function testQuantiles(): void
    {
        self::assertSame(150.0, Statistics::of('median', [100, 200, 150]));
        self::assertSame(125.0, Statistics::of('lower_quartile', [100, 200, 150]));
        self::assertNull(Statistics::of('median', []));
    }
}
