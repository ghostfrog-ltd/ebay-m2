<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\Engine;

/** eBay UK bid increments by current price, in GBP. */
final class BidIncrements
{
    /** Upper bound of each band => increment. */
    private const BANDS = [
        [1.00, 0.05],
        [5.00, 0.20],
        [15.00, 0.50],
        [60.00, 1.00],
        [150.00, 2.00],
        [300.00, 5.00],
        [600.00, 10.00],
        [1500.00, 20.00],
        [3000.00, 50.00],
    ];
    private const TOP = 100.00;

    public static function at(float $price): float
    {
        foreach (self::BANDS as [$upTo, $increment]) {
            if ($price <= $upTo) {
                return $increment;
            }
        }
        return self::TOP;
    }

    /** The lowest bid that beats $price. */
    public static function next(float $price): float
    {
        return round($price + self::at($price), 2);
    }
}
