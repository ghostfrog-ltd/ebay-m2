<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\Engine;

/**
 * Greedy buying for one pot and one run. Candidates are taken best score first and bought while the pot has
 * free cash and the per-item and per-category limits hold. Pure: no I/O.
 */
final class Selector
{
    public const BOUGHT = 'bought';
    public const SKIPPED = 'skipped';

    /**
     * @param list<array{key: int|string, category: string, score: float, reserve: float}> $candidates
     * @param array<string, float> $categoryCommitted Cash already committed per category.
     * @return array<int|string, array{outcome: string, reason: string}>
     */
    public static function select(
        array $candidates,
        float $freeCash,
        float $capital,
        float $maxItemPercent,
        float $maxCategoryPercent,
        array $categoryCommitted = []
    ): array {
        usort($candidates, static fn(array $a, array $b): int => [$b['score'], $a['key']] <=> [$a['score'], $b['key']]);
        $itemCap = $capital * $maxItemPercent / 100;
        $categoryCap = $capital * $maxCategoryPercent / 100;
        $results = [];
        foreach ($candidates as $candidate) {
            $reserve = $candidate['reserve'];
            $category = $candidate['category'];
            $committed = $categoryCommitted[$category] ?? 0.0;
            if ($reserve > $itemCap + 0.005) {
                $reason = 'item_cap';
            } elseif ($reserve > $freeCash + 0.005) {
                // Checked before the category cap so missed profit from a lack of cash is always reported.
                $reason = 'no_cash';
            } elseif ($committed + $reserve > $categoryCap + 0.005) {
                $reason = 'category_cap';
            } else {
                $freeCash -= $reserve;
                $categoryCommitted[$category] = $committed + $reserve;
                $results[$candidate['key']] = ['outcome' => self::BOUGHT, 'reason' => 'bought'];
                continue;
            }
            $results[$candidate['key']] = ['outcome' => self::SKIPPED, 'reason' => $reason];
        }
        return $results;
    }
}
