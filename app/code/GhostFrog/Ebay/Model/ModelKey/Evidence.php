<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey;

final class Evidence
{
    public static function texts(array $attrs, string $title, string ...$fields): array
    {
        $values = [$title];
        foreach ($fields as $field) {
            $value = $attrs[$field] ?? '';
            array_push($values, ...(is_array($value) ? array_values($value) : [$value]));
        }
        $texts = [];
        foreach ($values as $value) {
            if (is_string($value) || is_int($value) || is_float($value)) {
                $value = trim(mb_strtolower((string)$value));
                if ($value !== '') {
                    $texts[] = strtr($value, ['‐' => '-', '‑' => '-', '–' => '-', '—' => '-',
                        '“' => '"', '”' => '"', '″' => '"', "''" => '"']);
                }
            }
        }
        return $texts;
    }

    public static function matches(string $pattern, array $texts): array
    {
        $values = [];
        foreach ($texts as $text) {
            preg_match_all($pattern, $text, $groups);
            array_push($values, ...$groups[1]);
        }
        return array_values(array_unique($values));
    }

    public static function any(string $pattern, array $texts): bool
    {
        foreach ($texts as $text) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }
        return false;
    }

    public static function subset(array $found, array $allowed): bool
    {
        return array_diff($found, $allowed) === [];
    }

    public static function only(array $candidates): string
    {
        return count($candidates) === 1 ? (string)reset($candidates) : 'unknown';
    }

    public static function unique(array $values): array
    {
        return array_values(array_unique($values));
    }

    public static function screens(array $texts): array
    {
        return self::unique(array_map(static function (string $size): string {
            $diagonal = str_ends_with($size, '.0') ? substr($size, 0, -2) : $size;
            return str_replace('.', '', $diagonal) . 'in';
        }, self::matches('~(?<![\d.])(\d{1,2}(?:\.\d{1,2})?)\s*[- ]?\s*(?:in(?:ch(?:es)?)?\b|")~', $texts)));
    }

    public static function identifiers(array $texts): array
    {
        return self::matches('~\b(a\d{4})\b~', $texts);
    }

    public static function generations(array $texts): array
    {
        $numbers = array_merge(
            self::matches('~\b(\d{1,2})(?:st|nd|rd|th)?[ -]+gen(?:eration)?\b~', $texts),
            self::matches('~\bgen(?:eration)?[ -]*(\d{1,2})\b(?!\s*(?:gb|tb|mm|inch|"))~', $texts)
        );
        $words = ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4,
            'fifth' => 5, 'sixth' => 6, 'seventh' => 7, 'eighth' => 8];
        foreach (self::matches('~\b(first|second|third|fourth|fifth|sixth|seventh|eighth)\s+gen~', $texts) as $word) {
            $numbers[] = $words[$word];
        }
        return self::unique(array_map('intval', $numbers));
    }

    public static function ordinal(int $number): string
    {
        $suffix = in_array($number % 100, [11, 12, 13], true)
            ? 'th' : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$number % 10] ?? 'th');
        return $number . $suffix . '-gen';
    }
}
