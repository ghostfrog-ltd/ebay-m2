<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Test\Unit\Model\Sale;

use GhostFrog\EbayLedger\Model\Privacy\Redactor;
use GhostFrog\EbayLedger\Model\Sale\SaleParser;
use PHPUnit\Framework\TestCase;

class SaleParserTest extends TestCase
{
    private function order(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../../_files/fulfillment_order.json'), true);
    }

    public function testLinesKeepIdsSkuAmountsAndStatuses(): void
    {
        [$console, $joyCons] = SaleParser::lines($this->order());
        $this->assertSame('12-34567-00001', $console['ebay_order_id']);
        $this->assertSame('10055500001', $console['ebay_line_item_id']);
        $this->assertSame('256700000001', $console['ebay_item_id']);
        $this->assertSame('GF-000001-01', $console['sku']);
        $this->assertSame([95.0, 4.5, 0.0], [$console['line_price'], $console['postage'], $console['refunded']]);
        $this->assertSame('2026-12-08 19:12:01', $console['sold_at']);
        $this->assertSame(['PAID', 'NOT_STARTED', 0], [$console['payment_status'], $console['fulfillment_status'], $console['cancelled']]);
        $this->assertNull($joyCons['sku']);
        $this->assertSame(5.0, $joyCons['refunded']);
        $this->assertSame('FULFILLED', $joyCons['fulfillment_status']);
    }

    public function testCancelledOrdersAreFlagged(): void
    {
        $order = $this->order();
        $order['cancelStatus']['cancelState'] = 'CANCELED';
        $this->assertSame([1, 1], array_column(SaleParser::lines($order), 'cancelled'));
    }

    public function testStoredPayloadHasNoBuyerDetails(): void
    {
        $json = json_encode(Redactor::clean($this->order()));
        foreach (['some_buyer_123', 'Pat Buyer', 'pat@example.com', '07700900000', 'High St', 'AB1 2CD'] as $personal) {
            $this->assertStringNotContainsString($personal, $json);
        }
        $this->assertStringContainsString('GF-000001-01', $json);
        $this->assertStringContainsString('119.50', $json);
    }
}
