<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\StockUnit;

/** Stock unit references (GF-000001) and the SKUs made from them (GF-000001-01). */
final class Reference
{
    public static function format(string $prefix, int $number): string
    {
        if ($number < 1) {
            throw new \InvalidArgumentException('Reference numbers start at 1.');
        }
        return $prefix . str_pad((string)$number, 6, '0', STR_PAD_LEFT);
    }

    /** The SKU for one product of a stock unit: the reference itself when sold whole, else -01, -02 and so on. */
    public static function sku(string $reference, ?int $part = null): string
    {
        if ($part === null) {
            return $reference;
        }
        if ($part < 1 || $part > 99) {
            throw new \InvalidArgumentException('A stock unit has between 1 and 99 parts.');
        }
        return $reference . '-' . str_pad((string)$part, 2, '0', STR_PAD_LEFT);
    }
}
