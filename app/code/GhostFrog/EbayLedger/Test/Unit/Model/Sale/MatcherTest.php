<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Test\Unit\Model\Sale;

use GhostFrog\EbayLedger\Model\Sale\Matcher;
use PHPUnit\Framework\TestCase;

class MatcherTest extends TestCase
{
    private const LINE = ['ebay_order_id' => '12-1', 'ebay_line_item_id' => '100', 'sku' => 'GF-000001-01', 'quantity' => 1];
    private const PRODUCTS = ['GF-000001-01' => ['ledger_product_id' => '7', 'sku' => 'GF-000001-01']];

    public function testMatchesExactSku(): void
    {
        $this->assertSame(['match_status' => 'matched', 'ledger_product_id' => 7, 'match_note' => null],
            Matcher::match(self::LINE, self::PRODUCTS, []));
    }

    public function testNoSkuOrUnknownSkuIsUnmatched(): void
    {
        $this->assertSame('unmatched', Matcher::match(['sku' => null] + self::LINE, self::PRODUCTS, [])['match_status']);
        $result = Matcher::match(['sku' => 'GF-000009'] + self::LINE, self::PRODUCTS, []);
        $this->assertSame('unmatched', $result['match_status']);
        $this->assertStringContainsString('GF-000009', $result['match_note']);
    }

    public function testNeverMatchesAProductAnotherSaleHas(): void
    {
        $this->assertSame('unmatched', Matcher::match(self::LINE, self::PRODUCTS, [7 => '12-2/200'])['match_status']);
        // Its own earlier match is fine.
        $this->assertSame('matched', Matcher::match(self::LINE, self::PRODUCTS, [7 => '12-1/100'])['match_status']);
    }

    public function testMoreThanOneItemOnALineIsUnmatched(): void
    {
        $this->assertSame('unmatched', Matcher::match(['quantity' => 2] + self::LINE, self::PRODUCTS, [])['match_status']);
    }
}
