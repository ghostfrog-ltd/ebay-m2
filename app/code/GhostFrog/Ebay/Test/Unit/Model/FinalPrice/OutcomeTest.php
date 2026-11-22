<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Test\Unit\Model\FinalPrice;

use GhostFrog\Ebay\Model\Enrichment\TradingResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OutcomeTest extends TestCase
{
    #[DataProvider('saleCases')]
    public function testSaleOutcome(array $overrides, string $status, ?string $price): void
    {
        $fields = $overrides + [
            'type' => 'Chinese',
            'listing_status' => 'Completed',
            'end_time' => '2000-01-01T17:21:06Z',
            'bids' => '7',
            'reserve' => '<ReserveMet>true</ReserveMet>',
            'bin' => '<SoldAsBin>false</SoldAsBin>',
            'sold' => '<QuantitySold>1</QuantitySold>',
            'quantity' => '1',
            'currency' => 'GBP',
            'price' => '241.50',
            'extra' => '',
        ];
        $xml = <<<XML
        <GetItemResponse xmlns="urn:ebay:apis:eBLBaseComponents">
            <Ack>Success</Ack>
            <Item>
                <ItemID>123456789012</ItemID><ListingType>{$fields['type']}</ListingType><Quantity>{$fields['quantity']}</Quantity>
                <ListingDetails><EndTime>{$fields['end_time']}</EndTime></ListingDetails>
                <SellingStatus>
                    <ListingStatus>{$fields['listing_status']}</ListingStatus><BidCount>{$fields['bids']}</BidCount>
                    {$fields['reserve']}{$fields['sold']}{$fields['bin']}<CurrentPrice currencyID="{$fields['currency']}">{$fields['price']}</CurrentPrice>
                </SellingStatus>
                {$fields['extra']}
            </Item>
        </GetItemResponse>
        XML;

        $outcome = (new TradingResponse())->parse($xml, '123456789012', true);
        self::assertSame($status, $outcome['status']);
        self::assertSame($price, $outcome['final_price'] ?? null);
    }

    public static function saleCases(): iterable
    {
        yield 'completed GBP auction' => [[], 'sold', '241.50'];
        yield 'ended auction with processed sale' => [['listing_status' => 'Ended'], 'sold', '241.50'];
        yield 'positive bids without sale' => [['sold' => '<QuantitySold>0</QuantitySold>'], 'ended', null];
        yield 'unmet reserve' => [['reserve' => '<ReserveMet>false</ReserveMet>'], 'ended', null];
        yield 'missing reserve evidence' => [['reserve' => ''], 'ended', null];
        yield 'missing sale quantity' => [['sold' => ''], 'retry', null];
        yield 'unknown lifecycle' => [['listing_status' => 'CustomCode'], 'retry', null];
        yield 'early eBay processing' => [['listing_status' => 'Active'], 'retry', null];
        yield 'new future end time' => [['listing_status' => 'Active', 'end_time' => '2099-01-01T17:21:06Z'], 'live', null];
        yield 'malformed end date' => [['end_time' => '2000-02-31T17:21:06Z'], 'retry', null];
        yield 'auction bought at BIN' => [['bin' => '<SoldAsBin>true</SoldAsBin>'], 'ended', null];
        yield 'single fixed-price sale' => [['type' => 'FixedPriceItem', 'bids' => '0'], 'sold', '241.50'];
        yield 'fixed-price best offer' => [[
            'type' => 'FixedPriceItem',
            'extra' => '<BestOfferDetails><BestOfferEnabled>true</BestOfferEnabled></BestOfferDetails>',
        ], 'ended', null];
        yield 'fixed-price revision' => [[
            'type' => 'FixedPriceItem',
            'extra' => '<ReviseStatus><ItemRevised>true</ItemRevised></ReviseStatus>',
        ], 'ended', null];
        yield 'fixed-price variations' => [['type' => 'FixedPriceItem', 'extra' => '<Variations/>'], 'ended', null];
        yield 'foreign currency' => [['currency' => 'USD'], 'ended', null];
        yield 'non-positive amount' => [['price' => '0.00'], 'ended', null];
    }

    public function testUnavailableResponseDoesNotDeclareAnEnding(): void
    {
        $xml = '<GetItemResponse><Ack>Failure</Ack><Errors><ErrorCode>17</ErrorCode></Errors></GetItemResponse>';
        self::assertSame(
            ['status' => 'unavailable', 'reason' => 'trading_item_unavailable'],
            (new TradingResponse())->parse($xml, '123456789012', true)
        );
    }

    public function testUnparseableResponseRaisesARetryableError(): void
    {
        $this->expectException(RuntimeException::class);
        (new TradingResponse())->parse('<GetItemResponse>', '123456789012', true);
    }
}
