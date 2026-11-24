<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Notification;

use GhostFrog\Ebay\Model\Comps\Listings;
use GhostFrog\Ebay\Model\Comps\Pricing;
use GhostFrog\Ebay\Model\Comps\Valuation;
use GhostFrog\Ebay\Model\PriceRefresh\Settings;

final class Offers
{
    public function __construct(
        private readonly Listings $listings,
        private readonly Valuation $valuation,
        private readonly Pricing $pricing,
        private readonly Settings $settings
    ) {
    }

    public function forListing(int $id): array
    {
        $listing = $this->listings->live($id);
        if ($listing === null) {
            return [];
        }

        $value = $this->valuation->forLiveBatch([$listing])[$id] ?? [];
        if (!isset($value['meets_target'])) {
            return [];
        }
        $now = time();
        $end = Settings::timestamp($listing['end_time']);
        $remaining = $end === null ? null : $end - $now;
        if ($remaining !== null && $remaining <= 0) {
            return [];
        }
        $prices = [
            'bin' => $listing['sale_type'] === 'auction' ? null : $listing['price_bin_current'],
            'auction' => $listing['sale_type'] === 'bin' || $remaining === null
                || $remaining > Settings::AUCTION_ALERT_WINDOW ? null : $listing['price_bid_current'],
        ];
        $offers = [];
        foreach ($prices as $type => $price) {
            if ($price <= 0) {
                continue;
            }
            $pricing = $this->pricing->calculate((float)$value['sold_median'], (float)$price);
            if ($pricing['meets_target'] && $pricing['expected_profit'] > 0) {
                $maxAge = $type === 'auction' ? $this->settings->auctionAlertAge() : $this->settings->binInterval();
                $checked = Settings::timestamp($listing['last_price_checked_at']);
                $offers[$type] = $pricing + $listing + [
                    'comp_count' => $value['comp_count'],
                    'offer_price' => $price,
                    'fresh' => $this->settings->isFresh($listing, $now, $maxAge)
                        && ($type === 'bin' || $checked >= $end - Settings::AUCTION_ALERT_WINDOW),
                ];
            }
        }
        return $offers;
    }
}
