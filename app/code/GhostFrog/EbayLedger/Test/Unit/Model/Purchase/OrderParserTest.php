<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Test\Unit\Model\Purchase;

use GhostFrog\EbayLedger\Model\Ebay\Xml;
use GhostFrog\EbayLedger\Model\Purchase\OrderParser;
use PHPUnit\Framework\TestCase;

class OrderParserTest extends TestCase
{
    private function orders(): array
    {
        $xml = simplexml_load_file(__DIR__ . '/../../_files/get_orders_buyer.xml');
        return Xml::list(Xml::toArray($xml)['OrderArray']['Order']);
    }

    public function testSingleLineOrderTakesTheOrderPostageAndOtherCharges(): void
    {
        $units = OrderParser::units($this->orders()[0]);
        $this->assertCount(1, $units);
        $unit = $units[0];
        $this->assertSame('12-34567-89012', $unit['ebay_order_id']);
        $this->assertSame('256789012345-2345678901001', $unit['ebay_line_item_id']);
        $this->assertSame('256789012345', $unit['ebay_item_id']);
        $this->assertSame(175.0, $unit['item_price']);
        $this->assertSame(4.99, $unit['postage_paid']);
        // 186.07 total less 175.00 and 4.99: eBay's buyer protection fee.
        $this->assertSame(6.08, $unit['other_costs']);
        $this->assertSame(186.07, $unit['total_cost']);
        $this->assertSame('auction', $unit['purchase_method']);
        $this->assertSame('2026-11-27 09:12:44', $unit['purchased_at']);
        $this->assertNull($unit['shipped_at']);
        $this->assertSame('GBP', $unit['currency']);
    }

    public function testQuantitiesBecomeOneUnitEachAndCostsAddUp(): void
    {
        $units = OrderParser::units($this->orders()[1]);
        $this->assertCount(3, $units);
        $this->assertSame([1, 2, 1], array_column($units, 'unit_index'));
        $this->assertSame(['best_offer', 'best_offer', 'buy_it_now'], array_column($units, 'purchase_method'));
        $this->assertSame([10.0, 10.0, 19.99], array_column($units, 'item_price'));
        $this->assertSame(300, (int)round(array_sum(array_column($units, 'postage_paid')) * 100));
        $this->assertSame(4301, (int)round(array_sum(array_column($units, 'total_cost')) * 100));
        $this->assertSame('2026-11-21 10:00:00', $units[0]['shipped_at']);
    }
}
