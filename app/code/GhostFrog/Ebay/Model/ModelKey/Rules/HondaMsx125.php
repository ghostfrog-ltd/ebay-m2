<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey\Rules;

final class HondaMsx125 extends Rule
{
    private static function clean($value): string
    {
        if ($value === null) {
            return '';
        }
        return trim(self::stringify($value));
    }

    private static function blob(array $attrs, string $title): string
    {
        return mb_strtolower(implode(' ', array_map(static fn($value) => self::clean($value), array_values(array_filter([
            $title,
            ($attrs['Manufacturer'] ?? null),
            ($attrs['Make'] ?? null),
            ($attrs['Model'] ?? null),
            ($attrs['Type'] ?? null),
            ($attrs['Engine Size'] ?? null),
            ($attrs['Capacity (cc)'] ?? null),
        ], static fn($value) => self::clean($value))))));
    }

    private static function extractCapacity(array $attrs, string $title): string
    {
        foreach (['Capacity (cc)', 'Engine Size'] as $key) {
            $raw = self::clean(($attrs[$key] ?? null));
            if (!$raw) {
                continue;
            }
            $match = self::search('\\b(12[0-9]|124|125)\\b', $raw);
            if ($match) {
                return '125cc';
            }
        }
        if (self::search('\\b(msx\\s*125|msx-125|msx125|125cc|124cc)\\b', mb_strtolower($title))) {
            return '125cc';
        }
        return '';
    }

    public static function hondaMsx125ModelKey(array $attrs, string $title = ''): ?string
    {
        $blob = self::blob($attrs, $title);
        if (!self::contains($blob, 'honda')) {
            return self::UNKNOWN_KEY;
        }
        if (!self::any(['msx125', 'msx 125', 'msx-125', 'grom'], static fn($marker) => (self::contains($blob, $marker)))) {
            return self::UNKNOWN_KEY;
        }
        $capacity = self::extractCapacity($attrs, $title);
        $parts = ['honda-msx125'];
        if ($capacity) {
            $parts[] = $capacity;
        }
        return implode('-', $parts);
    }
}
