<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Collection;

use DateTimeImmutable;
use DateTimeZone;

final class ListingSummary
{
    public function externalId(array $item, bool $sellerMode): string
    {
        $id = $sellerMode && !empty($item['legacyItemId']) ? $item['legacyItemId'] : ($item['itemId'] ?? '');
        return is_string($id) ? trim($id) : '';
    }

    public function unavailable(array $item, DateTimeImmutable $now): bool
    {
        $end = $this->endTime($item);
        return ($end !== null && $end <= $now) || $this->availability($item) === false;
    }

    public function availability(array $item): ?bool
    {
        $unavailable = ['OUT_OF_STOCK', 'SOLD_OUT', 'ENDED', 'UNAVAILABLE'];
        $available = ['IN_STOCK', 'LIMITED_QUANTITY', 'AVAILABLE'];
        $status = strtoupper(trim((string)($item['availabilityStatus']
            ?? $item['availability'] ?? $item['estimatedAvailabilityStatus'] ?? '')));
        if (in_array($status, $unavailable, true)) {
            return false;
        }
        if (in_array($status, $available, true)) {
            return true;
        }

        $availabilities = $item['estimatedAvailabilities'] ?? [];
        if (!is_array($availabilities)) {
            return null;
        }
        if (!array_is_list($availabilities)) {
            $availabilities = [$availabilities];
        }
        $negative = false;
        foreach ($availabilities as $availability) {
            if (!is_array($availability)) {
                continue;
            }
            $status = strtoupper(trim((string)($availability['estimatedAvailabilityStatus']
                ?? $availability['availabilityStatus'] ?? '')));
            $quantity = $availability['estimatedAvailableQuantity']
                ?? $availability['availableQuantity'] ?? $availability['quantity'] ?? null;
            if (in_array($status, $available, true) || (is_numeric($quantity) && (float)$quantity > 0)) {
                return true;
            }
            if (in_array($status, $unavailable, true) || (is_numeric($quantity) && (float)$quantity === 0.0)) {
                $negative = true;
            } elseif ($status !== '') {
                return null;
            }
        }
        return $negative ? false : null;
    }

    public function normalize(array $item, string $requestedSaleType, DateTimeImmutable $now): ?array
    {
        $multi = $item['isMultiVariationListing'] ?? false;
        if (is_array($multi)) {
            $multi = $multi['__value__'] ?? $multi[0] ?? false;
        }
        $group = strtoupper(trim((string)($item['itemGroupType'] ?? '')));
        if ($multi === true || (is_string($multi) && strcasecmp($multi, 'true') === 0)
            || in_array($group, ['SELLER_DEFINED_VARIATIONS', 'GROUP', 'MULTI_SKU'], true)
            || array_key_exists('variations', $item) || array_key_exists('variation', $item)
        ) {
            return null;
        }

        $saleType = $this->saleType($item) ?? $requestedSaleType;
        if ($saleType !== 'both' && $saleType !== $requestedSaleType) {
            return null;
        }

        $prices = $this->prices($item, $saleType);
        $end = $this->endTime($item);
        return [
            'title' => mb_substr((string)($item['title'] ?? ''), 0, 255),
            'price_current' => $prices['price_bid_current'] ?? $prices['price_bin_current']
                ?? $this->price($item['price'] ?? []),
            'bids_count' => max(0, (int)($item['bidCount'] ?? 0)),
            'end_time' => $end?->format(DATE_ATOM),
            'url' => mb_substr((string)($item['itemWebUrl'] ?? $item['itemUrl'] ?? ''), 0, 1024),
            'sale_type' => $saleType,
            'time_left_s' => $end === null ? null : max(0, $end->getTimestamp() - $now->getTimestamp()),
            'raw_attrs' => json_encode((object)$this->evidence($item), JSON_THROW_ON_ERROR),
        ] + $prices;
    }

    public function saleType(array $item): ?string
    {
        $options = array_map('strtoupper', array_filter((array)($item['buyingOptions'] ?? []), 'is_string'));
        $auction = in_array('AUCTION', $options, true);
        $bin = in_array('FIXED_PRICE', $options, true);
        return match (true) {
            $auction && $bin => 'both',
            $auction => 'auction',
            $bin => 'bin',
            default => null,
        };
    }

    public function prices(array $item, string $saleType, bool $requireCurrency = false): array
    {
        return [
            'price_bid_current' => $saleType === 'bin' ? null : $this->price($item['currentBidPrice'] ?? [], $requireCurrency),
            'price_bin_current' => $saleType === 'auction' ? null : $this->price($item['price'] ?? [], $requireCurrency),
        ];
    }

    public function endTime(array $item): ?DateTimeImmutable
    {
        $value = $item['itemEndDate'] ?? '';
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    public function price(mixed $price, bool $requireCurrency = false): ?string
    {
        if (!is_array($price)) {
            return null;
        }
        $value = $price['value'] ?? null;
        if (($price['currency'] ?? ($requireCurrency ? null : 'GBP')) !== 'GBP'
            || (!is_string($value) && !is_int($value) && !is_float($value))
            || !preg_match('/\A([0-9]+)(?:\.([0-9]{1,2})0*)?\z/', (string)$value, $amount)
        ) {
            return null;
        }

        $pounds = ltrim($amount[1], '0') ?: '0';
        $pence = str_pad($amount[2] ?? '', 2, '0');
        if (strlen($pounds) > 10 || (int)$pounds > 2147483647
            || ($pounds === '2147483647' && $pence !== '00')
        ) {
            return null;
        }

        return $pounds . '.' . $pence;
    }

    private function evidence(array $item): array
    {
        $attrs = [];
        foreach (['condition', 'conditionDescription', 'description', 'shortDescription', 'subtitle',
            'categoryId', 'categoryPath', 'itemCreationDate', 'itemEndDate'] as $key) {
            $this->add($attrs, 'Browse ' . $key, $item[$key] ?? null, 4000);
        }
        foreach ([
            'seller' => ['username', 'feedbackScore', 'feedbackPercentage'],
            'itemLocation' => ['country', 'city', 'stateOrProvince', 'postalCode'],
            'returnTerms' => ['returnsAccepted', 'refundMethod', 'returnMethod'],
        ] as $group => $keys) {
            foreach ($keys as $key) {
                $this->add($attrs, "Browse $group $key", $item[$group][$key] ?? null, 255);
            }
        }

        $returnPeriod = $item['returnTerms']['returnPeriod'] ?? [];
        $this->add($attrs, 'Browse returnTerms returnPeriod', trim(
            ($returnPeriod['value'] ?? '') . ' ' . ($returnPeriod['unit'] ?? '')
        ), 255);

        $shipping = [];
        foreach (array_slice($item['shippingOptions'] ?? [], 0, 3) as $option) {
            $compact = array_intersect_key($option, array_flip(['shippingServiceCode', 'shippingCarrierCode', 'type']));
            if (isset($option['shippingCost']) && is_array($option['shippingCost'])) {
                $compact['shippingCost'] = array_intersect_key($option['shippingCost'], array_flip(['value', 'currency']));
            }
            if ($compact !== []) {
                $shipping[] = $compact;
            }
        }
        if ($shipping !== []) {
            $attrs['Browse shippingOptions'] = $shipping;
        }

        foreach ($item['localizedAspects'] ?? [] as $aspect) {
            $name = trim((string)($aspect['name'] ?? ''));
            if ($name !== '') {
                $this->add($attrs, 'Browse aspect ' . $name, $aspect['value'] ?? null, 1000);
            }
        }

        $images = array_merge(
            [$item['image'] ?? null, $item['thumbnailImage'] ?? null],
            $item['additionalImages'] ?? [],
            $item['thumbnailImages'] ?? []
        );
        $urls = [];
        foreach ($images as $image) {
            $url = is_string($image) ? $image : ($image['imageUrl'] ?? $image['url'] ?? '');
            $url = trim($url);
            if (preg_match('~^https?://~i', $url)) {
                $urls[$url] = $url;
            }
            if (count($urls) >= 12) {
                break;
            }
        }
        if ($urls !== []) {
            $attrs['Browse imageUrls'] = array_values($urls);
            $attrs['Browse imageUrl'] = reset($urls);
        }
        return $attrs;
    }

    private function add(array &$attrs, string $key, mixed $value, int $maxLength): void
    {
        if (is_string($value)) {
            $value = mb_substr(trim($value), 0, $maxLength);
        }
        if ($value !== null && $value !== '') {
            $attrs[$key] = $value;
        }
    }
}
