<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\Engine;

use GhostFrog\EbayShadow\Model\Config\Source\Statistic;

final class Statistics
{
    /** @param float[] $values */
    public static function of(string $statistic, array $values): ?float
    {
        return $statistic === Statistic::MEDIAN ? self::quantile($values, 0.5) : self::quantile($values, 0.25);
    }

    /** Linear-interpolated quantile (the "type 7" method used by spreadsheets and numpy). */
    public static function quantile(array $values, float $q): ?float
    {
        $values = array_values(array_map('floatval', $values));
        if ($values === []) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $position = ($q * (count($values) - 1));
        $low = (int)floor($position);
        $high = (int)ceil($position);
        return round($values[$low] + ($values[$high] - $values[$low]) * ($position - $low), 2);
    }
}
