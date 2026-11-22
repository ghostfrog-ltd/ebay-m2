<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\FinalPrice;

use DateTimeImmutable;
use DateTimeZone;
use DOMElement;
use DOMXPath;

final class Outcome
{
    public static function parse(DOMXPath $xpath, DOMElement $item, ?int $now = null): array
    {
        $status = strtolower(self::text($xpath, $item, 'SellingStatus/ListingStatus')
            ?: self::text($xpath, $item, 'ListingStatus'));
        $end = self::endTime(self::text($xpath, $item, 'ListingDetails/EndTime'));
        $now ??= time();
        if ($status === 'active' && $end !== null && $end->getTimestamp() > $now) {
            return ['status' => 'live', 'reason' => 'trading_still_live', 'end_time' => $end->format('Y-m-d H:i:s')];
        }
        if ($end === null || $end->getTimestamp() > $now || !in_array($status, ['ended', 'completed'], true)) {
            return ['status' => 'retry', 'reason' => 'trading_outcome_pending'];
        }

        $bids = self::count(self::text($xpath, $item, 'SellingStatus/BidCount'));
        $quantity = self::count(self::text($xpath, $item, 'SellingStatus/QuantitySold'));
        $ended = [
            'status' => 'ended',
            'reason' => 'ended_without_verified_sale',
            'end_time' => $end->format('Y-m-d H:i:s'),
            'bids_count' => $bids,
            'final_price' => null,
        ];
        $type = strtolower(self::text($xpath, $item, 'ListingType'));
        $reserve = self::text($xpath, $item, 'SellingStatus/ReserveMet');
        if ($type === 'chinese' && in_array($reserve, ['false', '0'], true)) {
            return array_replace($ended, ['reason' => 'reserve_not_met']);
        }
        if ($quantity === 0) {
            return $ended;
        }
        if ($quantity === null) {
            return ['status' => 'retry', 'reason' => 'trading_quantity_missing'];
        }

        $auction = $type === 'chinese' && $bids > 0 && in_array($reserve, ['true', '1'], true)
            && in_array(self::text($xpath, $item, 'SellingStatus/SoldAsBin'), ['', 'false', '0'], true);
        $bestOffer = self::text($xpath, $item, 'BestOfferDetails/BestOfferEnabled');
        $offerCount = self::text($xpath, $item, 'BestOfferDetails/BestOfferCount');
        $fixedPrice = in_array($type, ['fixedpriceitem', 'storesfixedprice'], true)
            && self::count(self::text($xpath, $item, 'Quantity')) === 1
            && in_array(self::text($xpath, $item, 'ReviseStatus/ItemRevised'), ['', 'false', '0'], true)
            && self::node($xpath, $item, 'SellingStatus/PromotionalSaleDetails') === null
            && self::node($xpath, $item, 'DiscountPriceInfo') === null;
        $price = self::node($xpath, $item, 'SellingStatus/CurrentPrice');
        $amount = trim((string)$price?->textContent);
        if ($quantity !== 1 || (!$auction && !$fixedPrice)
            || !in_array($bestOffer, ['', 'false', '0'], true) || !in_array($offerCount, ['', '0'], true)
            || self::node($xpath, $item, 'Variations') !== null
            || in_array(self::text($xpath, $item, 'SellingStatus/AdminEnded'), ['true', '1'], true)
            || $price?->getAttribute('currencyID') !== 'GBP'
            || !preg_match('/\A\d{1,10}(?:\.\d{1,2}0*)?\z/', $amount)
            || (float)$amount <= 0
        ) {
            return array_replace($ended, ['reason' => 'ended_price_unverified']);
        }

        return array_replace($ended, [
            'status' => 'sold',
            'reason' => $auction ? 'confirmed_auction_sale' : 'confirmed_fixed_price_sale',
            'final_price' => $amount,
        ]);
    }

    private static function count(string $value): ?int
    {
        return preg_match('/\A\d{1,10}\z/', $value) && (int)$value <= 2147483647 ? (int)$value : null;
    }

    private static function endTime(string $value): ?DateTimeImmutable
    {
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/', $value)) {
            return null;
        }
        $date = new DateTimeImmutable($value);
        return DateTimeImmutable::getLastErrors() === false ? $date->setTimezone(new DateTimeZone('UTC')) : null;
    }

    private static function text(DOMXPath $xpath, DOMElement $item, string $path): string
    {
        return trim((string)self::node($xpath, $item, $path)?->textContent);
    }

    private static function node(DOMXPath $xpath, DOMElement $item, string $path): ?DOMElement
    {
        $parts = array_map(static fn(string $name): string => '*[local-name()="' . $name . '"]', explode('/', $path));
        $node = $xpath->query('./' . implode('/', $parts), $item)->item(0);
        return $node instanceof DOMElement ? $node : null;
    }
}
