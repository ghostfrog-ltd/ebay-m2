<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Profit;

/** Profit arithmetic for the dashboard and the CSV: costs are positive, credits negative. */
final class Calculator
{
    /**
     * One product: what the buyer paid (item and postage) less refunds, its allocated cost, eBay's fees, postage
     * you paid (labels bought on eBay and postage you entered), packaging and other costs you entered.
     *
     * @param array $row sale_price, postage_charged, refunded, allocated_cost, ebay_fees, label_fees, postage_cost,
     *     packaging, other_costs (missing values count as 0).
     */
    public static function product(array $row): array
    {
        $value = static fn(string $key) => round((float)($row[$key] ?? 0), 2);
        $income = round($value('sale_price') + $value('postage_charged') - $value('refunded'), 2);
        $postage = round($value('label_fees') + $value('postage_cost'), 2);
        $costs = round($value('allocated_cost') + $value('ebay_fees') + $postage + $value('packaging') + $value('other_costs'), 2);
        return [
            'income' => $income,
            'postage_total' => $postage,
            'costs' => $costs,
            'profit' => round($income - $costs, 2),
        ];
    }

    /**
     * A stock unit: its products' profits, less costs entered against the unit itself. A unit returned or written
     * off makes its refund less what it cost.
     *
     * @param list<array> $products Product rows with profit and sold (bool).
     */
    public static function unit(array $unit, array $products, float $unitCosts): array
    {
        $cost = round((float)$unit['total_cost'], 2);
        $refund = round((float)($unit['refund_amount'] ?? 0), 2);
        if (in_array($unit['status'], ['returned_to_seller', 'written_off'], true) || $products === []) {
            $closed = in_array($unit['status'], ['returned_to_seller', 'written_off'], true);
            return [
                'profit' => $closed ? round($refund - $cost - $unitCosts, 2) : null,
                'complete' => $closed,
                'unsold_cost' => $closed ? 0.0 : round($cost - $refund + $unitCosts, 2),
            ];
        }
        $sold = array_filter($products, static fn(array $product) => $product['sold']);
        $profit = round(array_sum(array_column($sold, 'profit')) - $unitCosts, 2);
        $unsold = array_filter($products, static fn(array $product) => !$product['sold']);
        return [
            'profit' => $sold === [] ? null : $profit,
            'complete' => $unsold === [],
            'unsold_cost' => round(array_sum(array_map(static fn($product) => (float)$product['allocated_cost'], $unsold)), 2),
        ];
    }

    /** Days between two dates, or null when either is missing. */
    public static function days(?string $from, ?string $to): ?float
    {
        if (!$from || !$to) {
            return null;
        }
        $start = strtotime($from . (strlen($from) === 10 ? ' 00:00:00' : '') . ' UTC');
        $end = strtotime($to . (strlen($to) === 10 ? ' 00:00:00' : '') . ' UTC');
        return $start === false || $end === false || $end < $start ? null : round(($end - $start) / 86400, 1);
    }

    public static function money(float $amount): string
    {
        return ($amount < -0.004 ? '-£' : '£') . number_format(abs($amount), 2);
    }

    public static function average(array $values): ?float
    {
        $values = array_filter($values, static fn($value) => $value !== null);
        return $values === [] ? null : round(array_sum($values) / count($values), 1);
    }
}
