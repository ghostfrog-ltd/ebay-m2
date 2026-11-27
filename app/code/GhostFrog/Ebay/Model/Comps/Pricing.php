<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Comps;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * A buy meets the target when its profit covers both a fixed minimum and a share of the price paid, so a
 * £500 phone has to earn more than a £100 console. Each source may override either minimum, the
 * shipping cost and the selling fee, since a console costs more to post than a watch and eBay charges some
 * categories more than others; a null override falls back to the admin setting.
 *
 * Shipping uses the listing's own postage when Browse returned it: that is paid to get the item, and the
 * outbound leg costs at least as much again, or half the source estimate when the seller posts cheaper or free.
 * Without listing postage the source's combined inbound and outbound estimate applies.
 *
 * Buys are assumed to come from private sellers, so eBay's Buyer Protection fee is added to the price paid.
 * Seller account type isn't stored, and assuming the fee keeps business-seller buys on the safe side.
 */
final class Pricing
{
    public const CONFIG_PREFIX = 'ghostfrog_ebay/comps/';
    public const SELLING_FEE = 'selling_fee_percent';
    public const TARGET_ROI = 'target_roi_percent';
    public const SHIPPING = 'shipping';
    public const MAX_ROI_PERCENT = 1000.0;

    private const DEFAULTS = [
        'target_profit' => 50.0,
        self::TARGET_ROI => 0.0,
        self::SELLING_FEE => 13.0,
        self::SHIPPING => 12.0,
    ];
    private const PACKAGING_AND_RESERVE = 5.0;
    // eBay UK Buyer Protection fee: a flat £0.10 plus a rate on each band of the item price.
    private const BUYER_PROTECTION_FLAT = 0.10;
    private const BUYER_PROTECTION_BANDS = [[0.0, 0.07], [20.0, 0.04], [300.0, 0.02], [4000.0, 0.0]];

    public function __construct(private readonly ScopeConfigInterface $config)
    {
    }

    /**
     * @param array $source Listing or source row; reads source_min_profit, source_min_roi_percent, source_shipping,
     *     source_selling_fee_percent and the listing's raw_attrs postage.
     */
    public function calculate(float $median, float $price, array $source = []): array
    {
        [$minProfit, $minRoi] = $this->targets($source);
        $shipping = $this->shippingFor($source);
        $net = $median * (1 - $this->sellingFee($source) / 100) - $shipping - self::PACKAGING_AND_RESERVE;
        $profit = round($net - $price - self::buyerProtection($price), 2);
        $ceiling = max(0.0, round(min(
            self::maxPrice(1.0, $net - $minProfit),
            self::maxPrice(1 + $minRoi / 100, $net)
        ), 2));
        $required = max($minProfit, $price * $minRoi / 100);

        return [
            'max_safe_buy' => $ceiling,
            'expected_profit' => $profit,
            'expected_roi_percent' => round(100 * $profit / $price, 2),
            'meets_target' => $price <= $ceiling && $profit >= $required,
            'shipping' => $shipping,
        ];
    }

    /** @return array{0: float, 1: float} Minimum profit in GBP and minimum ROI percent. */
    public function targets(array $source = []): array
    {
        $profit = $source['source_min_profit'] ?? null;
        $roi = $source['source_min_roi_percent'] ?? null;
        return [
            $profit === null || $profit === '' ? $this->setting('target_profit') : (float)$profit,
            $roi === null || $roi === '' ? $this->setting(self::TARGET_ROI) : (float)$roi,
        ];
    }

    /** Selling fee percent charged on resale for a source, or the global setting when it has none. */
    public function sellingFee(array $source = []): float
    {
        $fee = $source['source_selling_fee_percent'] ?? null;
        return $fee === null || $fee === '' ? $this->setting(self::SELLING_FEE) : (float)$fee;
    }

    /** Combined inbound and outbound shipping in GBP for a source, or the global setting when it has none. */
    public function shipping(array $source = []): float
    {
        $shipping = $source['source_shipping'] ?? null;
        return $shipping === null || $shipping === '' ? $this->setting(self::SHIPPING) : (float)$shipping;
    }

    /** Inbound plus outbound shipping in GBP for a listing, from its own postage where known. */
    public function shippingFor(array $listing): float
    {
        $estimate = $this->shipping($listing);
        $postage = self::listingPostage($listing);
        return $postage === null ? $estimate : round($postage + max($postage, $estimate / 2), 2);
    }

    /** Cheapest GBP postage to the buyer that Browse returned for the listing, or null when it gave none. */
    public static function listingPostage(array $listing): ?float
    {
        $attrs = $listing['raw_attrs'] ?? null;
        if (is_string($attrs)) {
            $attrs = json_decode($attrs, true);
        }
        $costs = [];
        foreach (is_array($attrs) ? (array)($attrs['Browse shippingOptions'] ?? []) : [] as $option) {
            $cost = is_array($option) ? ($option['shippingCost'] ?? null) : null;
            if (is_array($cost) && ($cost['currency'] ?? 'GBP') === 'GBP' && is_numeric($cost['value'] ?? null)
                && (float)$cost['value'] >= 0) {
                $costs[] = (float)$cost['value'];
            }
        }
        return $costs === [] ? null : min($costs);
    }

    public static function buyerProtection(float $price): float
    {
        return round(self::BUYER_PROTECTION_FLAT + self::bandedFee($price), 2);
    }

    private static function bandedFee(float $price): float
    {
        $fee = 0.0;
        foreach (self::BUYER_PROTECTION_BANDS as $index => [$from, $rate]) {
            $to = self::BUYER_PROTECTION_BANDS[$index + 1][0] ?? INF;
            $fee += max(0.0, min($price, $to) - $from) * $rate;
        }
        return $fee;
    }

    /**
     * Largest price p where $multiple * p plus the Buyer Protection fee on p stays within $budget. Both terms
     * rise with p, so each fee band is solved as a straight line.
     */
    private static function maxPrice(float $multiple, float $budget): float
    {
        $budget -= self::BUYER_PROTECTION_FLAT;
        foreach (self::BUYER_PROTECTION_BANDS as $index => [$from, $rate]) {
            $to = self::BUYER_PROTECTION_BANDS[$index + 1][0] ?? INF;
            $price = $from + ($budget - $multiple * $from - self::bandedFee($from)) / ($multiple + $rate);
            if ($price <= $to) {
                return max(0.0, $price);
            }
        }
        return 0.0;
    }

    public static function validSetting(mixed $value, string $field): bool
    {
        $max = match ($field) {
            self::SELLING_FEE => 100.0,
            self::TARGET_ROI => self::MAX_ROI_PERCENT,
            default => 999999999.99,
        };
        return is_scalar($value) && preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/', trim((string)$value))
            && (float)$value <= $max;
    }

    public static function isSettingPath(string $path): bool
    {
        return $path === self::CONFIG_PREFIX . ResaleBasis::SETTING || (str_starts_with($path, self::CONFIG_PREFIX)
            && isset(self::DEFAULTS[substr($path, strlen(self::CONFIG_PREFIX))]));
    }

    private function setting(string $field): float
    {
        $value = $this->config->getValue(self::CONFIG_PREFIX . $field);
        return self::validSetting($value, $field) ? (float)$value : self::DEFAULTS[$field];
    }
}
