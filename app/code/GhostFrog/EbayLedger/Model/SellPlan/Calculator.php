<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\SellPlan;

/**
 * Works out what a stock unit makes sold whole, parted out, or in groups, and recommends one.
 *
 * Each sale nets its price less eBay's percentage fee, the fixed fee per sale, postage for its size band and
 * packaging. Every sale after the first also costs your effort. Profit is what the sales net less what the unit
 * cost you. Sales whose price is below the minimum confidence are flagged and left out of "certain profit", which
 * the recommendation is based on; "expected profit" counts every priced sale.
 */
final class Calculator
{
    public const WHOLE = 'whole';
    public const PART_OUT = 'part_out';
    public const MIXED = 'mixed';

    public const LABELS = [self::WHOLE => 'Sell whole', self::PART_OUT => 'Part out', self::MIXED => 'Mixed'];

    /**
     * @param array $input unit_cost, fee_percent, per_order_fee, packaging, effort_cost, postage (band => GBP),
     *     min_confidence, whole (price, comps, source, days, size_band), parts (present parts: name, group,
     *     size_band, price, comps, source, days), missing (absent template parts, same shape as parts).
     * @return array{options: array<string, array>, recommended: ?string, reasons: list<string>}
     */
    public static function plan(array $input): array
    {
        $options = [self::WHOLE => self::option(self::WHOLE, [self::wholeLine($input)], $input)];
        $parts = $input['parts'] ?? [];
        if (count($parts) > 1) {
            $options[self::PART_OUT] = self::option(self::PART_OUT,
                array_map(static fn(array $part) => self::line($part['name'], [$part]), $parts), $input);
            $grouped = [];
            foreach ($parts as $part) {
                $key = trim((string)($part['group'] ?? '')) ?: $part['name'];
                $grouped[$key][] = $part;
            }
            if (count($grouped) > 1 && count($grouped) < count($parts)) {
                $lines = [];
                foreach ($grouped as $name => $members) {
                    $lines[] = self::line(count($members) > 1 ? implode(' + ', array_column($members, 'name')) : $name, $members);
                }
                $options[self::MIXED] = self::option(self::MIXED, $lines, $input);
            }
        }
        return self::recommend($options);
    }

    private static function wholeLine(array $input): array
    {
        $whole = $input['whole'] ?? [];
        $price = isset($whole['price']) ? (float)$whole['price'] : null;
        $confidence = Confidence::of($price, (string)($whole['source'] ?? ''), (int)($whole['comps'] ?? 0));
        $notes = [];
        $missing = $input['missing'] ?? [];
        if ($price !== null && $missing !== []) {
            // Sold comparables are complete items, so take off what the missing parts are worth.
            $deduction = 0.0;
            $confidences = [$confidence];
            foreach ($missing as $part) {
                $partPrice = isset($part['price']) ? (float)$part['price'] : null;
                $deduction += $partPrice ?? 0.0;
                $confidences[] = $partPrice === null ? Confidence::LOW
                    : Confidence::of($partPrice, (string)$part['source'], (int)($part['comps'] ?? 0));
            }
            $price = max(0.0, round($price - $deduction, 2));
            $confidence = Confidence::lowest($confidences);
            $notes[] = sprintf('Missing %s: £%.2f taken off the complete price.',
                implode(', ', array_column($missing, 'name')), $deduction);
        }
        return [
            'name' => 'Whole item',
            'parts' => array_column($input['parts'] ?? [], 'name'),
            'price' => $price,
            'confidence' => $confidence,
            'size_band' => (string)($whole['size_band'] ?? 'large'),
            'days' => isset($whole['days']) ? (float)$whole['days'] : null,
            'notes' => $notes,
        ];
    }

    private static function line(string $name, array $members): array
    {
        $price = 0.0;
        $confidences = [];
        $days = [];
        $bands = [];
        foreach ($members as $part) {
            $partPrice = isset($part['price']) ? (float)$part['price'] : null;
            $confidences[] = Confidence::of($partPrice, (string)($part['source'] ?? ''), (int)($part['comps'] ?? 0));
            $price += $partPrice ?? 0.0;
            $days[] = isset($part['days']) ? (float)$part['days'] : null;
            $bands[] = (string)($part['size_band'] ?? 'small');
        }
        $confidence = Confidence::lowest($confidences);
        return [
            'name' => $name,
            'parts' => array_column($members, 'name'),
            'price' => $confidence === Confidence::NONE ? null : round($price, 2),
            'confidence' => $confidence,
            'size_band' => self::largestBand($bands),
            'days' => in_array(null, $days, true) ? null : max($days),
            'notes' => [],
        ];
    }

    private static function option(string $code, array $lines, array $input): array
    {
        $postage = $input['postage'] ?? [];
        $expected = 0.0;
        $certain = 0.0;
        $flagged = [];
        foreach ($lines as $index => $line) {
            $band = $line['size_band'];
            $postageCost = (float)($postage[$band] ?? $postage['large'] ?? max($postage ?: [0]));
            $line['postage'] = $postageCost;
            if ($line['price'] === null) {
                $line['fees'] = null;
                $line['net'] = null;
                $flagged[] = $line['name'];
                $lines[$index] = $line;
                continue;
            }
            $line['fees'] = round($line['price'] * (float)$input['fee_percent'] / 100 + (float)$input['per_order_fee'], 2);
            $line['net'] = round($line['price'] - $line['fees'] - $postageCost - (float)$input['packaging'], 2);
            $expected += $line['net'];
            if (Confidence::atLeast($line['confidence'], (string)$input['min_confidence'])) {
                $certain += $line['net'];
            } else {
                $flagged[] = $line['name'];
            }
            $lines[$index] = $line;
        }
        $effort = round(max(0, count($lines) - 1) * (float)$input['effort_cost'], 2);
        $cost = round((float)$input['unit_cost'], 2);
        $days = array_column($lines, 'days');
        return [
            'code' => $code,
            'label' => self::LABELS[$code],
            'lines' => $lines,
            'sales' => count($lines),
            'revenue' => round(array_sum(array_map(static fn($line) => $line['price'] ?? 0.0, $lines)), 2),
            'effort' => $effort,
            'unit_cost' => $cost,
            'expected_profit' => round($expected - $effort - $cost, 2),
            'certain_profit' => round($certain - $effort - $cost, 2),
            'flagged' => $flagged,
            'days' => in_array(null, $days, true) || $days === [] ? null : max($days),
            'complete' => !in_array(null, array_column($lines, 'price'), true),
        ];
    }

    private static function recommend(array $options): array
    {
        $ranked = array_values(array_filter($options, static fn(array $option) => $option['lines'][0]['price'] !== null
            || $option['code'] !== self::WHOLE));
        usort($ranked, static fn(array $a, array $b) => [$b['certain_profit'], $b['expected_profit'], $a['sales']]
            <=> [$a['certain_profit'], $a['expected_profit'], $b['sales']]);
        $best = $ranked[0] ?? null;
        $reasons = [];
        if ($best === null) {
            return ['options' => $options, 'recommended' => null,
                'reasons' => ['Nothing could be priced. Enter estimates for the parts and recalculate.']];
        }
        $reasons[] = sprintf('%s has the highest certain profit: %s (expected %s) from %d sale%s.',
            $best['label'], self::money($best['certain_profit']), self::money($best['expected_profit']), $best['sales'],
            $best['sales'] === 1 ? '' : 's');
        foreach ($ranked as $other) {
            if ($other['code'] !== $best['code']) {
                $reasons[] = sprintf('%s: certain %s, expected %s, %d sale%s.', $other['label'],
                    self::money($other['certain_profit']), self::money($other['expected_profit']), $other['sales'],
                    $other['sales'] === 1 ? '' : 's');
            }
        }
        if ($best['flagged'] !== []) {
            $reasons[] = 'Not counted as certain: ' . implode(', ', $best['flagged']) . '.';
        }
        if (isset($options[self::WHOLE]) && $best['code'] !== self::WHOLE) {
            $reasons[] = sprintf('Compared with selling whole, that is %s certain and %s expected.',
                self::money($best['certain_profit'] - $options[self::WHOLE]['certain_profit'], true),
                self::money($best['expected_profit'] - $options[self::WHOLE]['expected_profit'], true));
        }
        return ['options' => $options, 'recommended' => $best['code'], 'reasons' => $reasons];
    }

    public static function money(float $amount, bool $signed = false): string
    {
        $sign = $amount < -0.004 ? '-' : ($signed ? '+' : '');
        return $sign . '£' . number_format(abs($amount), 2);
    }

    private static function largestBand(array $bands): string
    {
        foreach (['large', 'medium', 'small'] as $band) {
            if (in_array($band, $bands, true)) {
                return $band;
            }
        }
        return $bands[0] ?? 'small';
    }
}
