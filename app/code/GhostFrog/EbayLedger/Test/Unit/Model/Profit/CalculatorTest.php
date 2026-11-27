<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Test\Unit\Model\Profit;

use GhostFrog\EbayLedger\Model\Profit\Calculator;
use PHPUnit\Framework\TestCase;

class CalculatorTest extends TestCase
{
    public function testProductProfit(): void
    {
        $result = Calculator::product([
            'sale_price' => '95.00', 'postage_charged' => '4.50', 'refunded' => '5.00', 'allocated_cost' => '20.66',
            'ebay_fees' => '12.08', 'label_fees' => '3.35', 'postage_cost' => null, 'packaging' => '1.20', 'other_costs' => '0',
        ]);
        // 95 + 4.50 - 5 = 94.50 in; 20.66 + 12.08 + 3.35 + 1.20 = 37.29 out.
        $this->assertSame(['income' => 94.5, 'postage_total' => 3.35, 'costs' => 37.29, 'profit' => 57.21], $result);
    }

    public function testUnitProfitSoFarAndUnsoldCost(): void
    {
        $unit = ['total_cost' => '32.97', 'refund_amount' => '0', 'status' => 'partly_sold'];
        $result = Calculator::unit($unit, [
            ['sold' => true, 'profit' => 57.21, 'allocated_cost' => 20.66],
            ['sold' => false, 'profit' => null, 'allocated_cost' => 12.31],
        ], 2.00);
        $this->assertSame(['profit' => 55.21, 'complete' => false, 'unsold_cost' => 12.31], $result);
    }

    public function testReturnedUnitIsRefundLessCost(): void
    {
        $unit = ['total_cost' => '32.97', 'refund_amount' => '28.17', 'status' => 'returned_to_seller'];
        $this->assertSame(['profit' => -6.80, 'complete' => true, 'unsold_cost' => 0.0], Calculator::unit($unit, [], 2.00));
    }

    public function testUnplannedUnitIsCashTiedUp(): void
    {
        $unit = ['total_cost' => '32.97', 'refund_amount' => '0', 'status' => 'in_transit'];
        $this->assertSame(['profit' => null, 'complete' => false, 'unsold_cost' => 32.97], Calculator::unit($unit, [], 0.0));
    }

    public function testDays(): void
    {
        $this->assertSame(2.5, Calculator::days('2026-11-27 00:00:00', '2026-11-29 12:00:00'));
        $this->assertSame(3.0, Calculator::days('2026-11-27', '2026-11-30'));
        $this->assertNull(Calculator::days(null, '2026-11-30'));
        $this->assertSame(2.0, Calculator::average([1.0, null, 3.0]));
    }
}
