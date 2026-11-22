<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey\Rules;

final class MiniCooper extends Rule
{
    private const GENERATION_RE = '~\\b(r5[0-9]|r6[0-1]|f5[4-7]|f60)\\b~ui';

    private const YEAR_RE = '~\\b(19\\d{2}|20[0-2]\\d)\\b~u';

    private const ENGINE_CC_RE = '~\\b(\\d{3,4})\\s?cc\\b~ui';

    private const ENGINE_L_RE = '~\\b(\\d\\.\\d)\\s?(?:l|ltr|litre)?\\b~ui';

    private const PART_NUMBER_KEYS = [
        'Manufacturer Part Number',
        'Reference OE/OEM Number',
        'OE/OEM Part Number',
        'Classic Part/Note',
    ];

    private const PART_MAP = [
        ['headlight', 'headlight'],
        ['headlamp', 'headlight'],
        ['tail light', 'taillight'],
        ['tail lamp', 'taillight'],
        ['taillight', 'taillight'],
        ['rear light', 'rearlight'],
        ['wing mirror', 'wingmirror'],
        ['door mirror', 'wingmirror'],
        ['mirror', 'mirror'],
        ['front bumper', 'frontbumper'],
        ['rear bumper', 'rearbumper'],
        ['bumper', 'bumper'],
        ['bonnet', 'bonnet'],
        ['bootlid', 'bootlid'],
        ['tailgate', 'tailgate'],
        ['door card', 'doorcard'],
        ['engine', 'engine'],
        ['gearbox', 'gearbox'],
        ['ecu', 'ecu'],
        ['steering wheel', 'steeringwheel'],
        ['alloy wheel', 'alloywheel'],
        ['wheel', 'wheel'],
        ['seat', 'seat'],
    ];

    private static function clean($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            return trim(implode(' ', array_map(static fn($v) => self::stringify($v), array_values(array_filter(self::iterate($value), static fn($v) => ($v !== null))))));
        }
        return trim(self::stringify($value));
    }

    private static function slug(string $value): string
    {
        return self::replacePattern('[^a-z0-9]+', '', mb_strtolower($value));
    }

    private static function joined(array $attrs, string $title): string
    {
        $candidates = [
            $title,
            ($attrs['Manufacturer'] ?? null),
            ($attrs['Brand'] ?? null),
            ($attrs['Model'] ?? null),
            ($attrs['Body Type'] ?? null),
            ($attrs['Engine'] ?? null),
            ($attrs['Engine Size'] ?? null),
        ];
        return mb_strtolower(implode(' ', array_map(static fn($value) => self::clean($value), array_values(array_filter($candidates, static fn($value) => self::clean($value))))));
    }

    private static function isMini(array $attrs, string $title): bool
    {
        $blob = self::joined($attrs, $title);
        $make = mb_strtolower(self::clean((($attrs['Manufacturer'] ?? null) ?: ($attrs['Brand'] ?? null))));
        if ($make === 'mini') {
            return true;
        }
        return ((self::contains(' ' . $blob . ' ', ' mini ')) && ((self::contains($blob, 'cooper')) || (self::contains($blob, 'countryman')) || (self::contains($blob, 'clubman')) || (self::contains($blob, 'paceman')) || (self::contains($blob, 'john cooper works')) || (self::contains($blob, 'jcw')) || (self::contains($blob, 'mini one'))));
    }

    private static function extractGeneration(array $attrs, string $title): string
    {
        $blob = self::joined($attrs, $title);
        $match = self::firstMatch(self::GENERATION_RE, $blob);
        return ($match ? mb_strtolower($match->group(1)) : '');
    }

    private static function extractYear(array $attrs, string $title): string
    {
        $blob = implode(' ', [
            self::clean(($attrs['Year'] ?? null)),
            self::clean(($attrs['Date of 1st Registration'] ?? null)),
            self::clean(($attrs['Year Manufactured'] ?? null)),
            $title,
        ]);
        $match = self::firstMatch(self::YEAR_RE, $blob);
        return ($match ? $match->group(1) : '');
    }

    private static function extractEngine(array $attrs, string $title): string
    {
        $blob = mb_strtolower(implode(' ', [
            self::clean(($attrs['Engine Size'] ?? null)),
            self::clean(($attrs['Engine'] ?? null)),
            self::clean(($attrs['Engine Number'] ?? null)),
            self::clean(($attrs['Drivetrain'] ?? null)),
            $title,
        ]));
        $ccMatch = self::firstMatch(self::ENGINE_CC_RE, $blob);
        if ($ccMatch) {
            return $ccMatch->group(1) . 'cc';
        }
        $litreMatch = self::firstMatch(self::ENGINE_L_RE, $blob);
        if ($litreMatch) {
            return $litreMatch->group(1) . 'l';
        }
        $rawCc = self::search('\\b(1199|1364|1397|1499|1598|1599|1995|1998)\\b', $blob);
        if ($rawCc) {
            $value = $rawCc->group(1);
            return $value . 'cc';
        }
        return '';
    }

    private static function extractFamily(array $attrs, string $title): string
    {
        $blob = self::joined($attrs, $title);
        $familyMap = [
            ['countryman', 'countryman'],
            ['clubman', 'clubman'],
            ['paceman', 'paceman'],
            ['roadster', 'roadster'],
            ['convertible', 'convertible'],
            ['cabrio', 'convertible'],
            ['coupe', 'coupe'],
            ['hatch', 'hatch'],
            ['hatchback', 'hatch'],
        ];
        foreach ($familyMap as [$needle, $family]) {
            if (self::contains($blob, $needle)) {
                return $family;
            }
        }
        $model = mb_strtolower(self::clean(($attrs['Model'] ?? null)));
        if ($model === 'one') {
            return 'hatch';
        }
        return '';
    }

    private static function extractTrim(array $attrs, string $title): string
    {
        $blob = self::joined($attrs, $title);
        $trimMap = [
            ['john cooper works', 'jcw'],
            ['jcw', 'jcw'],
            ['cooper sd', 'coopersd'],
            ['cooper s', 'coopers'],
            ['cooper d', 'cooperd'],
            ['cooper', 'cooper'],
            ['mini one', 'one'],
            [' one ', 'one'],
        ];
        foreach ($trimMap as [$needle, $trim]) {
            if (self::contains(' ' . $blob . ' ', $needle)) {
                return $trim;
            }
        }
        return '';
    }

    private static function extractPartNumber(array $attrs): string
    {
        foreach (self::PART_NUMBER_KEYS as $key) {
            $raw = self::clean(($attrs[$key] ?? null));
            if (!$raw) {
                continue;
            }
            $low = mb_strtolower($raw);
            if (in_array($low, ['n/a', 'na', 'does not apply', 'unknown'], true)) {
                continue;
            }
            return mb_substr(self::slug($raw), 0, 24);
        }
        return '';
    }

    private static function extractPart(array $attrs, string $title): string
    {
        $blob = self::joined($attrs, $title);
        foreach (self::PART_MAP as [$needle, $token]) {
            if (self::contains($blob, $needle)) {
                return $token;
            }
        }
        return '';
    }

    public static function miniCooperModelKey(array $attrs, string $title = ''): ?string
    {
        if (!self::isMini($attrs, $title)) {
            return self::UNKNOWN_KEY;
        }
        $family = self::extractFamily($attrs, $title);
        $trim = self::extractTrim($attrs, $title);
        $generation = self::extractGeneration($attrs, $title);
        $engine = self::extractEngine($attrs, $title);
        $year = self::extractYear($attrs, $title);
        $part = self::extractPart($attrs, $title);
        $partNumber = self::extractPartNumber($attrs);
        $baseParts = ['mini'];
        if ($family) {
            $baseParts[] = $family;
        }
        if ($trim && (!in_array($trim, $baseParts, true))) {
            $baseParts[] = $trim;
        }
        if ($generation) {
            $baseParts[] = $generation;
        }
        if ($part) {
            $baseParts[] = $part;
            if ($partNumber) {
                $baseParts[] = $partNumber;
            }
        } else {
            if ($engine) {
                $baseParts[] = $engine;
            }
            if ($year) {
                $baseParts[] = $year;
            }
        }
        if (self::length($baseParts) === 1) {
            return self::UNKNOWN_KEY;
        }
        return implode('-', $baseParts);
    }
}
