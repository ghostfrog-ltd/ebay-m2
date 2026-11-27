<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\SellPlan;

use GhostFrog\Ebay\Api\SoldComparablesInterface;
use GhostFrog\EbayLedger\Model\Config\Settings;

/**
 * Gathers what the calculator needs for one stock unit: the parts that arrived, prices from core's verified sold
 * comparables (same model and grade), your estimates where core has none, and the sell plan settings.
 */
class Builder
{
    /** Comparables whose newest sale is older than this count as low confidence. */
    private const STALE_DAYS = 120;

    public function __construct(
        private readonly Templates $templates,
        private readonly SoldComparablesInterface $comparables,
        private readonly Settings $settings
    ) {
    }

    /**
     * @param array $unit Stock unit row.
     * @param array $inputs present (part names ticked) and overrides (part name => GBP), from a saved draft or the form.
     * @return array{template: ?array, grade: string, grade_note: ?string, input: array, result: array, parts: list<array>}
     */
    public function build(array $unit, array $inputs = []): array
    {
        $template = $this->templates->forModel($unit['model_key'] ?? null);
        [$grade, $gradeNote] = self::compsGrade($unit);
        $modelKey = (string)($unit['model_key'] ?? '');
        $whole = $modelKey === '' ? ['price' => null, 'comps' => 0, 'days' => null, 'stale' => false]
            : $this->comps($modelKey, $grade);

        $parts = [];
        foreach ($template['parts'] ?? [] as $part) {
            $parts[] = $this->price($part, $grade, $inputs['overrides'][$part['name']] ?? null)
                + ['present' => $this->present($part['name'], $unit, $inputs)];
        }
        $present = array_values(array_filter($parts, static fn(array $part) => $part['present']));
        $missing = array_values(array_filter($parts, static fn(array $part) => !$part['present']));
        $input = [
            'unit_cost' => round((float)$unit['total_cost'] - (float)($unit['refund_amount'] ?? 0), 2),
            'fee_percent' => $this->settings->sellingFeePercent(),
            'per_order_fee' => $this->settings->perOrderFee(),
            'packaging' => $this->settings->packaging(),
            'effort_cost' => $this->settings->effortCost(),
            'postage' => $this->settings->postage(),
            'min_confidence' => $this->settings->minConfidence(),
            'whole' => $whole + ['source' => $whole['stale'] ? 'old_comps' : 'comps',
                'size_band' => $template['whole_size_band'] ?? 'large'],
            'parts' => $present,
            'missing' => $missing,
        ];
        return [
            'template' => $template,
            'grade' => $grade,
            'grade_note' => $gradeNote,
            'input' => $input,
            'result' => Calculator::plan($input),
            'parts' => $parts,
        ];
    }

    /** @return array{0: string, 1: ?string} The grade to price at, and why when it differs from the unit's. */
    public static function compsGrade(array $unit): array
    {
        $actual = strtoupper((string)($unit['actual_grade'] ?? ''));
        $grade = $actual ?: strtoupper((string)($unit['predicted_grade'] ?? '')) ?: 'B';
        $note = $actual === '' ? sprintf('Not graded yet, so priced at %s.', $grade) : null;
        if ($grade === 'D') {
            return ['C', 'Core has no grade D sales, so priced at grade C: expect less.'];
        }
        return [$grade, $note];
    }

    private function present(string $name, array $unit, array $inputs): bool
    {
        if (isset($inputs['present']) && is_array($inputs['present'])) {
            return in_array($name, $inputs['present'], true);
        }
        $contents = json_decode((string)($unit['contents'] ?? ''), true);
        if (!is_array($contents) || $contents === []) {
            return true;
        }
        $wanted = self::normalise($name);
        foreach ($contents as $line) {
            $have = self::normalise((string)$line);
            if ($have !== '' && (str_contains($have, $wanted) || str_contains($wanted, $have))) {
                return true;
            }
        }
        return false;
    }

    private function price(array $part, string $grade, mixed $override): array
    {
        $base = [
            'name' => (string)$part['name'],
            'group' => (string)($part['group'] ?? ''),
            'size_band' => (string)($part['size_band'] ?? 'small'),
            'comp_model_key' => (string)($part['comp_model_key'] ?? ''),
            'estimate' => is_numeric($part['estimate'] ?? null) ? (float)$part['estimate'] : null,
        ];
        if (is_numeric($override) && (float)$override > 0) {
            return $base + ['price' => round((float)$override, 2), 'comps' => 0, 'days' => null, 'source' => 'override'];
        }
        if ($base['comp_model_key'] !== '') {
            $comps = $this->comps($base['comp_model_key'], $grade);
            if ($comps['price'] !== null) {
                return $base + $comps + ['source' => $comps['stale'] ? 'old_comps' : 'comps'];
            }
        }
        return $base + ['price' => $base['estimate'], 'comps' => 0, 'days' => null,
            'source' => $base['estimate'] === null ? 'none' : 'estimate'];
    }

    /**
     * Median sold price, how many sold, days between sales, and whether the newest sale is too old to trust.
     *
     * @return array{price: ?float, comps: int, days: ?float, stale: bool, last_sale: ?string}
     */
    private function comps(string $modelKey, string $grade): array
    {
        $days = $this->settings->compsDays();
        $sales = $this->comparables->sales($modelKey, $grade, gmdate('Y-m-d H:i:s', time() - $days * 86400), gmdate('Y-m-d H:i:s'));
        $prices = [];
        $last = null;
        foreach ($sales as $sale) {
            if ((float)$sale['final_price'] > 0) {
                $prices[] = (float)$sale['final_price'];
                $last = max($last ?? '', (string)$sale['end_time']);
            }
        }
        $count = count($prices);
        return [
            'price' => $count === 0 ? null : self::median($prices),
            'comps' => $count,
            'days' => $count === 0 ? null : round($days / $count, 1),
            'stale' => $last !== null && strtotime($last . ' UTC') < time() - self::STALE_DAYS * 86400,
            'last_sale' => $last,
        ];
    }

    public static function median(array $values): float
    {
        sort($values);
        $middle = intdiv(count($values), 2);
        return round(count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2, 2);
    }

    private static function normalise(string $text): string
    {
        return trim((string)preg_replace('/[^a-z0-9]+/', ' ', strtolower($text)));
    }
}
