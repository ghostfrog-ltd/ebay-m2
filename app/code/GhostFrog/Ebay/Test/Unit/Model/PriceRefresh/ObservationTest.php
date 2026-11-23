<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Test\Unit\Model\PriceRefresh;

use DateTimeImmutable;
use GhostFrog\Ebay\Model\Collection\ListingSummary;
use GhostFrog\Ebay\Model\PriceRefresh\Observation;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ObservationTest extends TestCase
{
    private const ID = 'v1|900000000001|12345';

    public function testCombinedPriceAndOpeningAuctionPriceAreKeptSeparate(): void
    {
        $now = new DateTimeImmutable('2026-11-23T20:00:00Z');
        $observation = new Observation(new ListingSummary());
        $item = $this->item();
        $fields = $observation->fields(self::ID, $item, $now);
        self::assertSame('both', $fields['sale_type']);
        self::assertSame('97.01', $fields['price_current']);
        self::assertSame('97.01', $fields['price_bid_current']);
        self::assertSame('142.50', $fields['price_bin_current']);
        self::assertSame(3, $fields['bids_count']);
        self::assertSame('2026-11-23 21:00:00', $fields['end_time']);

        unset($item['currentBidPrice']);
        $item['bidCount'] = 0;
        $item['minimumPriceToBid'] = ['value' => '80', 'currency' => 'GBP'];
        $fields = $observation->fields(self::ID, $item, $now);
        self::assertSame('80.00', $fields['price_current']);
        self::assertSame('142.50', $fields['price_bin_current']);

        $item['buyingOptions'] = ['AUCTION'];
        self::assertNull($observation->fields(self::ID, $item, $now)['price_bin_current']);
    }

    public function testMismatchedVariationNeverVerifiesAnOffer(): void
    {
        $this->expectException(RuntimeException::class);
        (new Observation(new ListingSummary()))->fields('900000000001', $this->item(), new DateTimeImmutable());
    }

    public function testMissingOrClosedItemLeavesOutcomeUnconfirmed(): void
    {
        $observation = new Observation(new ListingSummary());
        $now = new DateTimeImmutable('2026-11-23T21:10:00Z');
        self::assertSame(['status' => 'unknown'], $observation->fields(self::ID, null, $now));
        $fields = $observation->fields(self::ID, $this->item(), $now);
        self::assertSame('unknown', $fields['status']);
        self::assertSame(0, $fields['time_left_s']);
        self::assertArrayNotHasKey('final_price', $fields);
        self::assertArrayNotHasKey('price_current', $fields);
    }

    public function testFixedPriceNeedsPositiveStockAndAUsableGbpPrice(): void
    {
        $observation = new Observation(new ListingSummary());
        $now = new DateTimeImmutable('2026-11-23T20:00:00Z');
        $item = ['buyingOptions' => ['FIXED_PRICE']] + $this->item();
        $inStock = ['estimatedAvailabilities' => [['estimatedAvailabilityStatus' => 'IN_STOCK']]] + $item;
        self::assertSame('142.50', $observation->fields(self::ID, $inStock, $now)['price_current']);
        $outOfStock = ['estimatedAvailabilities' => [['estimatedAvailabilityStatus' => 'OUT_OF_STOCK']]] + $item;
        self::assertSame('unknown', $observation->fields(self::ID, $outOfStock, $now)['status']);
        $this->expectException(RuntimeException::class);
        $observation->fields(self::ID, $item, $now);
    }

    private function item(): array
    {
        return [
            'itemId' => self::ID,
            'buyingOptions' => ['AUCTION', 'FIXED_PRICE'],
            'currentBidPrice' => ['value' => '97.01', 'currency' => 'GBP'],
            'price' => ['value' => '142.50', 'currency' => 'GBP'],
            'bidCount' => 3,
            'itemEndDate' => '2026-11-23T21:00:00.000Z',
        ];
    }
}
