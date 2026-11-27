<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Product;

use GhostFrog\EbayLedger\Model\Money;
use GhostFrog\EbayLedger\Model\SellPlan\Calculator;
use GhostFrog\EbayLedger\Model\StockUnit\Reference;
use InvalidArgumentException;

/**
 * The products a confirmed plan makes: one SKU per sale, and the unit's cost shared across them in proportion to
 * each one's planned price, to the penny. The cost is what you paid (item, postage and other charges) less any
 * refund. Once stored on a product it is never recalculated silently.
 */
final class Allocation
{
    /**
     * @param array $unit Stock unit row (reference, title, total_cost, refund_amount, actual_grade, predicted_grade).
     * @param array $option One option from Calculator::plan().
     * @return list<array{sku: string, line_number: int, part_name: string, parts: list<string>, name: string,
     *     planned_price: float, allocated_cost: float, grade: ?string}>
     * @throws InvalidArgumentException When a sale has no planned price to share the cost by.
     */
    public static function products(array $unit, array $option): array
    {
        $unpriced = array_column(array_filter($option['lines'], static fn(array $line) => !($line['price'] > 0)), 'name');
        if ($unpriced !== []) {
            throw new InvalidArgumentException('Give every sale a price before confirming: ' . implode(', ', $unpriced) . '.');
        }
        $whole = $option['code'] === Calculator::WHOLE;
        $cost = round((float)$unit['total_cost'] - (float)($unit['refund_amount'] ?? 0), 2);
        $shares = Money::split($cost, array_column($option['lines'], 'price'));
        $grade = ($unit['actual_grade'] ?? null) ?: ($unit['predicted_grade'] ?? null);
        $products = [];
        foreach (array_values($option['lines']) as $index => $line) {
            $number = $whole ? 0 : $index + 1;
            $sku = Reference::sku((string)$unit['reference'], $whole ? null : $number);
            $products[] = [
                'sku' => $sku,
                'line_number' => $number,
                'part_name' => $line['name'],
                'parts' => $line['parts'],
                'name' => mb_substr(sprintf('%s %s', $sku, $whole ? $unit['title'] : $line['name'] . ' – ' . $unit['title']), 0, 255),
                'planned_price' => round((float)$line['price'], 2),
                'allocated_cost' => $shares[$index],
                'grade' => $grade,
            ];
        }
        return $products;
    }
}
