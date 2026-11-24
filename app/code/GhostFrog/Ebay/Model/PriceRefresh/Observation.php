<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\PriceRefresh;

use DateTimeImmutable;
use DateTimeZone;
use GhostFrog\Ebay\Model\Collection\ListingSummary;
use RuntimeException;

final class Observation
{
    public function __construct(private readonly ListingSummary $summary)
    {
    }

    public function fields(string $externalId, ?array $item, DateTimeImmutable $now): array
    {
        if ($item === null) {
            return ['status' => 'unknown'];
        }

        $identity = static fn (string $id): string => preg_replace('/^v[12]\|/', '', $id);
        $expected = ctype_digit($externalId) ? $externalId . '|0' : $identity($externalId);
        $returned = $item['itemId'] ?? null;
        if (!is_string($returned) || !preg_match('/\Av[12]\|[0-9]+\|[0-9]+\z/', $returned)
            || $identity($returned) !== $expected
        ) {
            throw new RuntimeException('eBay returned a different item or variation.');
        }

        $end = $this->summary->endTime($item);
        if ($end === null && !empty($item['itemEndDate'])) {
            throw new RuntimeException('eBay returned an invalid end time.');
        }
        $schedule = [
            'end_time' => $end?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'time_left_s' => $end === null ? null : max(0, $end->getTimestamp() - $now->getTimestamp()),
        ];
        if ($this->summary->unavailable($item, $now)) {
            return ['status' => 'unknown'] + array_filter($schedule, static fn ($value) => $value !== null);
        }

        $type = $this->summary->saleType($item);
        if ($type === null || ($type !== 'bin' && $end === null)) {
            throw new RuntimeException('eBay returned incomplete buying options or auction deadline.');
        }
        if ($type === 'bin' && $this->summary->availability($item) !== true) {
            throw new RuntimeException('eBay returned no positive stock evidence for Buy It Now.');
        }
        $prices = $this->summary->prices($item, $type, requireCurrency: true);
        $bids = filter_var($item['bidCount'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $bid = $prices['price_bid_current'];
        if ($bid <= 0) {
            $bid = $type !== 'bin' && $bids === 0
                ? $this->summary->price($item['minimumPriceToBid'] ?? [], requireCurrency: true)
                : null;
        }
        $fixed = $prices['price_bin_current'];
        $bid = $bid > 0 ? $bid : null;
        $fixed = $fixed > 0 ? $fixed : null;
        $price = $bid ?? $fixed;
        if ($price === null) {
            throw new RuntimeException('eBay returned no usable GBP price.');
        }
        return [
            'status' => 'live',
            'sale_type' => $type,
            'price_current' => $price,
            'bids_count' => $type === 'bin' ? 0 : ($bids === false ? null : $bids),
            'price_bid_current' => $bid,
            'price_bin_current' => $fixed,
        ] + $schedule;
    }
}
