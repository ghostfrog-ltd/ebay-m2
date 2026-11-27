<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Test\Unit\Model\Engine;

use GhostFrog\EbayShadow\Model\Engine\ForwardSales;
use PHPUnit\Framework\TestCase;

final class ForwardSalesTest extends TestCase
{
    private const DAY = 86400;

    private int $decided;

    protected function setUp(): void
    {
        $this->decided = strtotime('2026-11-01 00:00:00 UTC');
    }

    private function sale(float $price, float $days): array
    {
        return ['item_id' => (string)$price, 'listing_id' => (int)$price, 'final_price' => $price,
            'end_time' => gmdate('Y-m-d H:i:s', $this->decided + (int)($days * self::DAY))];
    }

    private function resolve(array $sales, float $nowDays, array $settings = []): ?array
    {
        $bought = $this->decided + self::DAY;
        $listed = $this->decided + 7 * self::DAY;
        return ForwardSales::resolve(SettingsFixture::defaults($settings), 200.0, 5.0, $this->decided, $bought,
            $listed, $this->decided + (int)($nowDays * self::DAY), $sales);
    }

    public function testWaitsUntilTheItemIsListed(): void
    {
        self::assertNull($this->resolve([$this->sale(100, 1), $this->sale(200, 2), $this->sale(300, 3)], 5));
    }

    public function testSellsAtTheStatisticOfLaterSalesOnly(): void
    {
        $sales = [$this->sale(999, -2), $this->sale(100, 1), $this->sale(200, 2), $this->sale(300, 3)];
        $result = $this->resolve($sales, 10);
        self::assertSame('forward', $result['method']);
        self::assertCount(3, $result['forward_sales']);
        // Lower quartile of 100, 200, 300 is 150, less 5% grade risk.
        self::assertSame(142.5, $result['sell_price']);
        // Three sales in ten elapsed days: one every 3.33 days.
        self::assertSame(3.33, $result['days_to_sell']);
        self::assertSame(false, $result['stale']);
    }

    public function testMedianCanBeChosen(): void
    {
        $sales = [$this->sale(100, 1), $this->sale(200, 2), $this->sale(300, 3)];
        self::assertSame(190.0, $this->resolve($sales, 10, ['sell/statistic' => 'median'])['sell_price']);
    }

    public function testTooFewSalesWaitsInsideTheWindow(): void
    {
        self::assertNull($this->resolve([$this->sale(100, 1)], 20));
    }

    public function testStaleItemWaitsForItsHoldPeriodThenIsMarkedDown(): void
    {
        $stale = $this->resolve([$this->sale(100, 1)], 40);
        self::assertTrue($stale['stale']);
        self::assertNull($stale['method']);

        $sold = $this->resolve([$this->sale(100, 1)], 62);
        self::assertSame('markdown', $sold['method']);
        // 200 x 0.75 x 0.95.
        self::assertSame(142.5, $sold['sell_price']);
        self::assertSame(gmdate('Y-m-d', $this->decided + 61 * self::DAY), gmdate('Y-m-d', $sold['sold_at']));
    }

    public function testStaleItemCanBeWrittenOff(): void
    {
        $result = $this->resolve([], 62, ['sell/stale_action' => 'write_off']);
        self::assertSame('write_off', $result['method']);
        self::assertSame(0.0, $result['sell_price']);
    }

    public function testSalesAfterTheWindowAreIgnored(): void
    {
        $sales = [$this->sale(100, 31), $this->sale(200, 32), $this->sale(300, 33)];
        self::assertTrue($this->resolve($sales, 40)['stale']);
    }
}
