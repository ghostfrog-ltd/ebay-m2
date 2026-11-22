<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey\Rules;

final class Bikes extends Rule
{
    /**
     * Basic string cleaner:
     * - Convert None → ""
     * - Strip whitespace
     */
    private static function clean($s): string
    {
        if ($s === null) {
            return '';
        }
        return trim(self::stringify($s));
    }

    /**
     * Normalise manufacturer → brand segment for the key.
     *
     * Rules:
     * - Use Manufacturer only
     * - Lowercase
     * - Remove spaces and non-alphanumeric chars
     */
    private static function normaliseBrand($raw): string
    {
        $s = self::clean($raw);
        if (!$s) {
            return '';
        }
        $out = [];
        foreach (mb_str_split(mb_strtolower($s)) as $ch) {
            if (self::isAlphanumeric($ch)) {
                $out[] = $ch;
            }
        }
        return implode('', $out);
    }

    /**
     * Remove anything inside parentheses, including the parentheses themselves.
     * Example:
     *     "YZF R6 (YZF600)" -> "YZF R6 "
     */
    private static function stripParentheses(string $s): string
    {
        $cleaned = [];
        $depth = 0;
        foreach (mb_str_split($s) as $ch) {
            if ($ch === '(') {
                $depth += 1;
                continue;
            }
            if ($ch === ')') {
                if ($depth > 0) {
                    $depth -= 1;
                }
                continue;
            }
            if ($depth === 0) {
                $cleaned[] = $ch;
            }
        }
        return implode('', $cleaned);
    }

    /**
     * Normalise small displacement differences into standard bike classes.
     * Only adjusts values within plausible ranges; otherwise returns original cc.
     */
    private static function normaliseCapacity(?int $cc): ?int
    {
        if ($cc === null) {
            return null;
        }
        if (120 <= $cc && $cc <= 129) {
            return 125;
        }
        if (110 <= $cc && $cc <= 119) {
            return 115;
        }
        if (230 <= $cc && $cc <= 239) {
            return 232;
        }
        if (240 <= $cc && $cc <= 255) {
            return 250;
        }
        if (298 <= $cc && $cc <= 305) {
            return 300;
        }
        if (306 <= $cc && $cc <= 324) {
            return 320;
        }
        if (325 <= $cc && $cc <= 350) {
            return 350;
        }
        if (395 <= $cc && $cc <= 404) {
            return 400;
        }
        if (595 <= $cc && $cc <= 609) {
            return 600;
        }
        if (643 <= $cc && $cc <= 655) {
            return 650;
        }
        if (670 <= $cc && $cc <= 699) {
            return 690;
        }
        if (695 <= $cc && $cc <= 705) {
            return 700;
        }
        if (730 <= $cc && $cc <= 760) {
            return 750;
        }
        if (795 <= $cc && $cc <= 810) {
            return 800;
        }
        if (995 <= $cc && $cc <= 1010) {
            return 1000;
        }
        return $cc;
    }

    /**
     * Normalise a bike model into a stable token.
     *
     * - Remove parentheses and junk
     * - Collapse separators
     * - Remove 'abs' token (MT-07 ABS → mt07)
     * - Remove trailing year-like suffixes (e.g. 21/22/23)
     * - Preserve significant variant codes (k5, k7, r6 etc.)
     */
    private static function normaliseModel($raw): string
    {
        $s = self::clean($raw);
        if (!$s) {
            return '';
        }
        $s = self::stripParentheses($s);
        $s = str_replace('/', ' ', $s);
        $s = str_replace('\\', ' ', $s);
        $s = str_replace('-', ' ', $s);
        $s = implode(' ', self::words($s));
        if (!$s) {
            return '';
        }
        $tokens = [];
        foreach (self::words($s) as $tok) {
            $alnum = self::alphanumeric($tok);
            if (!$alnum) {
                continue;
            }
            $lower = mb_strtolower($alnum);
            if ($lower === 'abs') {
                continue;
            }
            $tokens[] = $lower;
        }
        if (!$tokens) {
            return '';
        }
        if ((self::length($tokens) > 1) && self::isDigit($tokens[count($tokens) -1]) && (self::length($tokens[count($tokens) -1]) === 2)) {
            $yy = intval($tokens[count($tokens) -1]);
            if (15 <= $yy && $yy <= 25) {
                $tokens = array_slice($tokens, 0, -1);
            }
        }
        $model = implode('', $tokens);
        if ($model === '0') {
            return '';
        }
        return $model;
    }

    /**
     * Parse capacity (cc) from attrs.
     *
     * Priority:
     * 1) Capacity (cc)
     * 2) Engine Size
     *
     * Rules:
     * - Convert to int if possible
     * - If value is 0 or negative → skip (treat as missing)
     */
    private static function parseCapacityCc(array $attrs): ?int
    {
        foreach (['Capacity (cc)', 'Engine Size'] as $key) {
            $raw = ($attrs[$key] ?? null);
            $s = self::clean($raw);
            if (!is_numeric($s)) {
                continue;
            }
            $cc = (int)(float)$s;
            if ($cc <= 0) {
                continue;
            }
            return $cc;
        }
        return null;
    }

    /**
     * Build a canonical model key for bikes (motomine, etc.) using ONLY attrs.
     *
     * Output format (examples):
     *     suzuki-gsxr600-600cc
     *     honda-cmx500-500cc
     *     ktm-125duke-125cc
     *     bmw-r1200gs-1200cc
     *
     * Rules:
     * - brand: from Manufacturer, normalised
     * - model: from Model, normalised
     * - capacity: from Capacity (cc) or Engine Size, appended as "{cc}cc"
     * - We IGNORE Year to avoid fragmenting comps
     * - If brand or model missing/invalid → return "unknown" (no suffix)
     * - If capacity is missing or 0 → no capacity suffix
     * - `title` accepted but ignored
     */
    public static function bikeModelKey(array $attrs, string $title = ''): string
    {
        $brand = self::normaliseBrand(($attrs['Manufacturer'] ?? null));
        $model = self::normaliseModel(($attrs['Model'] ?? null));
        $capacityCc = self::normaliseCapacity(self::parseCapacityCc($attrs));
        if (!$brand || !$model) {
            return self::UNKNOWN_KEY;
        }
        $parts = [$brand . '-' . $model];
        if ($capacityCc !== null) {
            $parts[] = $capacityCc . 'cc';
        }
        return implode('-', $parts);
    }
}
