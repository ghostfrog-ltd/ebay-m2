<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\Engine;

use GhostFrog\EbayShadow\Model\Config\Source\StaleAction;

/**
 * Resolves the sale of a listed shadow item from verified sales of the same model and grade that ended after
 * the decision, so the result never uses what was known when buying. Pure: no I/O.
 */
final class ForwardSales
{
    public const FORWARD = 'forward';
    public const MARKDOWN = 'markdown';
    public const WRITE_OFF = 'write_off';

    /**
     * @param list<array{item_id: string, listing_id: int, final_price: float, end_time: string}> $sales Later sales.
     * @return array|null Null while waiting. Otherwise: method, sell_price, days_to_sell, sold_at, stale,
     *     forward_sales (the sales used) and window_end. A stale item still inside its hold period returns
     *     method null with stale true.
     */
    public static function resolve(
        array $settings,
        float $predictedResale,
        float $gradeRiskPercent,
        int $decidedAt,
        int $boughtAt,
        int $listedAt,
        int $now,
        array $sales
    ): ?array {
        if ($now < $listedAt) {
            return null;
        }
        $windowEnd = Lifecycle::add($decidedAt, (float)$settings['sell/forward_window_days']);
        $until = min($now, $windowEnd);
        $used = array_values(array_filter($sales, static function (array $sale) use ($decidedAt, $until): bool {
            $end = strtotime($sale['end_time'] . ' UTC');
            return $end !== false && $end > $decidedAt && $end <= $until;
        }));
        $base = ['window_end' => $windowEnd, 'forward_sales' => $used];

        if (count($used) >= max(1, (int)$settings['sell/min_forward_sales'])) {
            $price = Statistics::of($settings['sell/statistic'], array_column($used, 'final_price'));
            $elapsed = max(1, $until - $decidedAt) / 86400;
            $days = round(max(1.0, min((float)$settings['sell/max_days_to_sell'], $elapsed / count($used))), 2);
            return $base + [
                'method' => self::FORWARD,
                'sell_price' => round($price * (1 - $gradeRiskPercent / 100), 2),
                'days_to_sell' => $days,
                'sold_at' => Lifecycle::add($listedAt, $days),
                'stale' => false,
            ];
        }
        if ($now < $windowEnd) {
            return null;
        }

        $staleAt = max($listedAt, Lifecycle::add($boughtAt, (float)$settings['sell/max_hold_days']));
        if ($now < $staleAt) {
            return $base + ['method' => null, 'stale' => true];
        }
        $days = round(($staleAt - $listedAt) / 86400, 2);
        if ($settings['sell/stale_action'] === StaleAction::WRITE_OFF) {
            return $base + ['method' => self::WRITE_OFF, 'sell_price' => 0.0, 'days_to_sell' => $days,
                'sold_at' => $staleAt, 'stale' => true];
        }
        $price = $predictedResale * (1 - $settings['sell/stale_markdown_percent'] / 100) * (1 - $gradeRiskPercent / 100);
        return $base + ['method' => self::MARKDOWN, 'sell_price' => round($price, 2), 'days_to_sell' => $days,
            'sold_at' => $staleAt, 'stale' => true];
    }
}
