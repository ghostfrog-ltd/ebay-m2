<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\Engine;

use Closure;

/**
 * Shadow's money arithmetic for one candidate under one settings snapshot. Pure: no I/O.
 *
 * Buy cost is price paid plus core's Buyer Protection fee plus inbound postage. A sale nets the sell price less
 * the final value fee (core's selling fee for the source), the per-order fee, outbound postage, packaging and
 * the expected returns cost (returns rate x two-way postage and packaging).
 *
 * Cautious profit = predicted resale x (1 - cautious discount) x (1 - grade-risk discount), netted as a sale,
 * less buy cost.
 */
final class Pricer
{
    /** @param Closure(float): float $buyerProtection */
    public function __construct(
        private readonly array $settings,
        private readonly Closure $buyerProtection,
        private readonly float $sellingFeePercent,
        private readonly float $inboundPostage,
        private readonly float $outboundPostage,
        private readonly float $gradeRiskPercent
    ) {
    }

    public function buyCost(float $price): float
    {
        return round($price + ($this->buyerProtection)($price) + $this->inboundPostage, 2);
    }

    /** @return array{fees: float, postage: float, packaging: float, returns_cost: float, net: float} */
    public function saleNet(float $sellPrice): array
    {
        $fees = round($sellPrice * $this->sellingFeePercent / 100 + $this->settings['costs/per_order_fee'], 2);
        $packaging = $this->settings['outbound/packaging'];
        $returns = round($this->settings['costs/returns_rate_percent'] / 100 * (2 * $this->outboundPostage + $packaging), 2);
        return [
            'fees' => $fees,
            'postage' => $this->outboundPostage,
            'packaging' => $packaging,
            'returns_cost' => $returns,
            'net' => round($sellPrice - $fees - $this->outboundPostage - $packaging - $returns, 2),
        ];
    }

    public function gradeRiskPercent(): float
    {
        return $this->gradeRiskPercent;
    }

    public function cautiousSellPrice(float $predictedResale): float
    {
        return round($predictedResale * (1 - $this->settings['evidence/cautious_discount_percent'] / 100)
            * (1 - $this->gradeRiskPercent / 100), 2);
    }

    public function cautiousProfit(float $predictedResale, float $price): float
    {
        return round($this->saleNet($this->cautiousSellPrice($predictedResale))['net'] - $this->buyCost($price), 2);
    }

    public function roiPercent(float $profit, float $price): float
    {
        $cost = $this->buyCost($price);
        return $cost > 0 ? round(100 * $profit / $cost, 2) : 0.0;
    }

    /**
     * Highest price, to the penny, that keeps cautious profit at or above the minimum and ROI at or above the
     * minimum, and whose buy cost stays within $costCap. Null when no positive price qualifies.
     */
    public function maxBid(float $predictedResale, ?float $costCap = null): ?float
    {
        $minProfit = $this->settings['capital/min_cautious_profit'];
        $minRoi = $this->settings['capital/min_roi_percent'];
        $ok = function (float $price) use ($predictedResale, $minProfit, $minRoi, $costCap): bool {
            $profit = $this->cautiousProfit($predictedResale, $price);
            return $profit >= $minProfit && $this->roiPercent($profit, $price) >= $minRoi
                && ($costCap === null || $this->buyCost($price) <= $costCap);
        };
        // Every term rises with price, so the qualifying prices form one range from zero.
        $low = 0;
        $high = (int)ceil($predictedResale * 100);
        if (!$ok(0.01)) {
            return null;
        }
        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            if ($ok($mid / 100)) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }
        return $low > 0 ? $low / 100 : null;
    }

    /** Days from purchase until the payout is spendable again, with $daysToSell days listed. */
    public static function cycleDays(array $settings, float $daysToSell): float
    {
        return round(Lifecycle::inboundDays($settings) + $settings['outbound/listing_days'] + $daysToSell
            + $settings['outbound/dispatch_days'] + $settings['outbound/delivery_days']
            + $settings['costs/payout_delay_days'], 2);
    }

    /** Profit per GBP of buy cost per day of the cycle. */
    public static function score(float $profit, float $buyCost, float $cycleDays): float
    {
        return $buyCost > 0 && $cycleDays > 0 ? round($profit / ($buyCost * $cycleDays), 8) : 0.0;
    }

    /** Predicted days to sell from how often the model and grade sold in the window before the decision. */
    public static function daysToSell(int $salesInWindow, int $windowDays, float $maxDays): float
    {
        if ($salesInWindow <= 0) {
            return $maxDays;
        }
        return round(max(1.0, min($maxDays, $windowDays / $salesInWindow)), 2);
    }

    /** Outbound postage for a category: the band price, or core's outbound estimate for "core". */
    public static function outboundPostage(array $settings, string $category, float $coreShipping, ?float $inbound): float
    {
        $band = (string)($settings['outbound/category_bands'][$category] ?? 'default');
        $price = $settings['outbound/postage'][$band] ?? ($settings['outbound/postage']['default'] ?? 'core');
        if (is_numeric($price)) {
            return round((float)$price, 2);
        }
        // Core's combined estimate is inbound + max(inbound, estimate / 2), or the estimate when postage is unknown.
        return round($inbound === null ? $coreShipping / 2 : max(0.0, $coreShipping - $inbound), 2);
    }

    public static function inboundPostage(float $coreShipping, ?float $inbound): float
    {
        return round($inbound ?? $coreShipping / 2, 2);
    }

    public static function gradeRisk(array $settings, string $grade): float
    {
        return (float)($settings['evidence/grade_risk'][$grade] ?? 0.0);
    }
}
