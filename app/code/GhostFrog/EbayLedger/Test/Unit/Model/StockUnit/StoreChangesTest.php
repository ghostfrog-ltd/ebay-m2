<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Test\Unit\Model\StockUnit;

use GhostFrog\EbayLedger\Model\Ebay\Xml;
use GhostFrog\EbayLedger\Model\Purchase\OrderParser;
use GhostFrog\EbayLedger\Model\StockUnit\Status;
use GhostFrog\EbayLedger\Model\StockUnit\Store;
use PHPUnit\Framework\TestCase;

/** Importing the same purchase twice changes nothing; a dispatch moves it on; your edits are kept. */
class StoreChangesTest extends TestCase
{
    private function unit(): array
    {
        $xml = simplexml_load_file(__DIR__ . '/../../_files/get_orders_buyer.xml');
        return OrderParser::units(Xml::list(Xml::toArray($xml)['OrderArray']['Order'])[0])[0];
    }

    /** The row as MySQL returns it after the first import: strings and decimals with two places. */
    private function stored(array $unit, string $status = Status::PURCHASED): array
    {
        $row = array_map(static fn($value) => is_float($value) ? number_format($value, 2, '.', '') : $value, $unit);
        return $row + [
            'stock_unit_id' => '1', 'status' => $status, 'purchase_method_source' => 'ebay', 'listing_id' => '42',
        ];
    }

    public function testSecondImportOfTheSamePurchaseChangesNothing(): void
    {
        $unit = $this->unit();
        $this->assertSame([], Store::changes($this->stored($unit), $unit, ['listing_id' => 42]));
    }

    public function testDispatchMovesAPurchaseToInTransit(): void
    {
        $unit = $this->unit();
        $stored = $this->stored($unit);
        $unit['shipped_at'] = '2026-11-28 08:00:00';
        $this->assertSame(['shipped_at' => '2026-11-28 08:00:00', 'status' => Status::IN_TRANSIT],
            Store::changes($stored, $unit, null));
    }

    public function testReceivedUnitsKeepTheirCostsAndManualMethod(): void
    {
        $unit = $this->unit();
        $stored = $this->stored($unit, Status::RECEIVED);
        $stored['purchase_method'] = 'best_offer';
        $stored['purchase_method_source'] = 'manual';
        $unit['item_price'] = 1.0;
        $unit['total_cost'] = 1.0;
        $this->assertSame([], Store::changes($stored, $unit, null));
    }
}
