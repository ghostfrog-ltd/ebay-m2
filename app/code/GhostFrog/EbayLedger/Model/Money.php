<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model;

/** Penny-exact money splitting. */
final class Money
{
    /**
     * Splits an amount across weights so the parts add up to the amount exactly, to the penny.
     * Pennies left by rounding go to the parts with the largest remainders, earliest first on ties.
     * With no positive weight the amount is split evenly.
     *
     * @param array<int|string, float|int> $weights
     * @return array<int|string, float> Same keys as the weights.
     */
    public static function split(float $amount, array $weights): array
    {
        if ($weights === []) {
            return [];
        }
        $pennies = (int)round($amount * 100);
        $weights = array_map(static fn($weight) => max(0.0, (float)$weight), $weights);
        $total = array_sum($weights);
        if ($total <= 0) {
            $weights = array_map(static fn() => 1.0, $weights);
            $total = (float)count($weights);
        }
        $parts = [];
        $remainders = [];
        $order = 0;
        foreach ($weights as $key => $weight) {
            $exact = $pennies * $weight / $total;
            $parts[$key] = (int)($exact < 0 ? ceil($exact) : floor($exact));
            $remainders[] = [abs($exact - $parts[$key]), $order++, $key];
        }
        $left = $pennies - array_sum($parts);
        usort($remainders, static fn(array $a, array $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
        $step = $left < 0 ? -1 : 1;
        for ($i = 0; $i < abs($left); $i++) {
            $parts[$remainders[$i % count($remainders)][2]] += $step;
        }
        return array_map(static fn(int $part) => round($part / 100, 2), $parts);
    }
}
