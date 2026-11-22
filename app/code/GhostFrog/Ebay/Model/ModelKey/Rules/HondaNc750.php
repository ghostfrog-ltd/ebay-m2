<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey\Rules;

final class HondaNc750 extends Rule
{
    private static function clean($s): string
    {
        if ($s === null) {
            return '';
        }
        return trim(self::stringify($s));
    }

    /**
     * Normalise brand to a compact token.
     *
     * NOTE: For ebay-honda-nc750 listings, Brand is often the SELLER (e.g. MOTOPART4U),
     * not Honda. We still include it to distinguish aftermarket suppliers.
     */
    private static function normaliseBrand($raw): string
    {
        $s = mb_strtolower(self::clean($raw));
        if (!$s) {
            return '';
        }
        return self::alphanumeric($s);
    }

    /**
     * Extract the bike family from compatibility fields.
     *
     * We deliberately collapse everything to:
     *   - nc700x
     *   - nc750x
     *
     * This avoids exploding keys by year ranges.
     */
    private static function extractBikeFamily(array $attrs): string
    {
        $candidates = [($attrs['Compatible Make'] ?? null), ($attrs['Fitment 1'] ?? null), ($attrs['Fitment 2'] ?? null)];
        $joined = mb_strtolower(implode(' ', array_map(static fn($c) => self::clean($c), $candidates)));
        if (str_contains($joined, 'nc750')) {
            return 'nc750x';
        }
        if (str_contains($joined, 'nc700')) {
            return 'nc700x';
        }
        return '';
    }

    /**
     * Extract a stable part identifier.
     *
     * Prefer Manufacturer Part Number / OEM reference.
     */
    private static function extractPartToken(array $attrs): string
    {
        foreach (['Manufacturer Part Number', 'Reference OE/OEM Number'] as $key) {
            $val = self::clean(($attrs[$key] ?? null));
            if (!$val) {
                continue;
            }
            $low = mb_strtolower($val);
            if (in_array($low, ['n/a', 'na', 'does not apply'], true)) {
                continue;
            }
            return self::alphanumeric(mb_strtolower($val));
        }
        return '';
    }

    /**
     * Canonical model key for ebay-honda-nc750 listings.
     *
     * Output format:
     *
     *     {brand}-{bike}-{part}
     *
     * Examples:
     *     Brand="MOTOPART4U",
     *     Compatible Make="For Honda NC750X",
     *     Manufacturer Part Number="MOT-083"
     *
     *     -> motopart4u-nc750x-mot083
     *
     * Notes:
     * - Brand is aftermarket supplier, not Honda.
     * - Bike family is collapsed to nc700x / nc750x.
     * - Year range is intentionally ignored.
     */
    public static function hondaNc750ModelKey(array $attrs, string $title = ''): ?string
    {
        $brand = self::normaliseBrand(($attrs['Brand'] ?? null));
        $bike = self::extractBikeFamily($attrs);
        $part = self::extractPartToken($attrs);
        if (!$brand || !$bike || !$part) {
            return self::UNKNOWN_KEY;
        }
        return $brand . '-' . $bike . '-' . $part;
    }
}
