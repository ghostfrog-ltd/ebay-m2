<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey\Rules;

final class Lego extends Rule
{
    private const MINIFIG_THEME_MAP = [
        'sw' => 'star-wars',
        'sh' => 'super-heroes',
        'hp' => 'harry-potter',
        'col' => 'collectible-minifigures',
        'loc' => 'legends-of-chima',
        'mk' => 'monkie-kid',
        'mo' => 'monkie-kid',
        'njo' => 'ninjago',
        'njoa' => 'ninjago',
        'pj' => 'ninjago',
        'poc' => 'pirates-of-the-caribbean',
        'pop' => 'prince-of-persia',
        'spd' => 'spiderman',
        'toy' => 'toy-story',
        'u' => 'ultra-agents',
        'vid' => 'vidiyo',
        'v' => 'vidiyo',
        'ww' => 'western',
        'w' => 'western',
        'sp' => 'space',
        'cas' => 'castle',
    ];

    private static function clean($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            return implode(' ', array_map(static fn($item) => self::clean($item), self::iterate($value)));
        }
        return mb_strtolower(trim(self::stringify($value)));
    }

    private static function attrBlob(array $attrs): string
    {
        return implode(' ', array_map(static fn($value) => self::clean($value), array_values(array_filter(array_values($attrs), static fn($value) => self::clean($value)))));
    }

    private static function blob(array $attrs, string $title): string
    {
        return trim(self::clean($title) . ' ' . self::attrBlob($attrs));
    }

    private static function isNotRealLego(string $text): bool
    {
        return self::containsAny($text, [
            'non lego',
            'non-lego',
            'not lego',
            'lego compatible',
            'compatible with lego',
            'lego style',
            'lego type',
            'building block set',
            'building blocks',
            'mega bloks',
            'megabloks',
            'lepin',
            'decool',
            'sluban',
            'mould king',
            'cada ',
            'qing yi',
        ]);
    }

    private static function isBulkOrJoblot(string $text): bool
    {
        return (self::containsAny($text, [
            'job lot',
            'joblot',
            'bulk lego',
            'bulk bricks',
            'mixed lego',
            'random lego',
            'mystery box',
            'uncounted',
        ]) ?: (bool)(self::search('\\b\\d+(?:\\.\\d+)?\\s?kg\\b', $text)));
    }

    private static function isPartialOrAccessory(string $text): bool
    {
        return self::containsAny($text, [
            'promo',
            'promotional',
            'incomplete',
            'partial',
            'missing pieces',
            'missing parts',
            'no minifigure',
            'no minifigures',
            'no mini figures',
            'without minifigures',
            'without minifigs',
            'build only',
            'builds only',
            'instructions only',
            'instructions and stickers only',
            'instruction book only',
            'manual only',
            'stickers only',
            'box only',
            'empty box',
            'custom',
            'replacement sticker',
            'sticker sheet only',
            'spare parts',
            'parts only',
            'minifigure only',
            'minifig only',
        ]);
    }

    private static function isSubBuildWithoutWholeSetSignal(string $text): bool
    {
        $subBuildTerms = ['castle byers'];
        $wholeSetTerms = [
            'complete set',
            'complete lego set',
            'full set',
            'sealed set',
            'new sealed',
            'new and sealed',
            'brand new sealed',
        ];
        return (self::containsAny($text, $subBuildTerms) && !self::containsAny($text, $wholeSetTerms));
    }

    private static function isMinifigureListing(array $attrs, string $title): bool
    {
        $text = self::blob($attrs, $title);
        return self::any([
            'minifigure',
            'minifig',
            'mini figure',
            'item type minifigure',
            'part type minifigure',
        ], static fn($term) => (self::contains($text, $term)));
    }

    private static function parseMinifigId(string $text): string
    {
        $match = self::search('\\b([a-z]{1,5})(\\d{3,5}[a-z]?)\\b', $text);
        if (!$match) {
            return '';
        }
        [$prefix, $ident] = $match->groups();
        $theme = (self::MINIFIG_THEME_MAP[$prefix] ?? $prefix);
        return 'lego-minifig-' . $theme . '-' . $ident;
    }

    private static function validSetNumber(string $candidate): bool
    {
        if (!$candidate || !self::isDigit($candidate)) {
            return false;
        }
        if ((self::length($candidate) < 4) || (self::length($candidate) > 7)) {
            return false;
        }
        $asInt = intval($candidate);
        return !(1950 <= $asInt && $asInt <= 2035);
    }

    private static function candidateNumbers(string $text): array
    {
        $found = [];
        foreach (self::findAll('\\b(\\d{4,7})\\b', $text) as $raw) {
            if (self::validSetNumber($raw) && (!in_array($raw, $found, true))) {
                $found[] = $raw;
            }
        }
        return $found;
    }

    private static function parseSetNumberFromAttrs(array $attrs): string
    {
        $candidateKeys = [
            'LEGO Set Number',
            'MPN',
            'Manufacturer Part Number',
            'Model Number',
            'Model',
            'LEGO Set Number:',
            'Numéro de l\'assortiment LEGO',
            'NumÃ©ro de l\'assortiment LEGO',
            'Herstellernummer',
        ];
        foreach ($candidateKeys as $key) {
            $raw = self::clean(($attrs[$key] ?? null));
            if (!$raw || (str_contains($raw, 'does not apply')) || (str_contains($raw, 'not apply'))) {
                continue;
            }
            $numbers = self::candidateNumbers($raw);
            if (self::length($numbers) === 1) {
                return $numbers[0];
            }
        }
        return '';
    }

    private static function parseSetNumberFromTitle(string $title): string
    {
        $titleText = self::clean($title);
        $explicit = [];
        foreach (['\\b(?:lego|set|model|item)\\s*(?:set)?\\s*[:#-]?\\s*(\\d{4,7})\\b', '\\((\\d{4,7})\\)'] as $pattern) {
            foreach (self::findMatches($pattern, $titleText) as $match) {
                $number = $match->group(1);
                if (self::validSetNumber($number) && (!in_array($number, $explicit, true))) {
                    $explicit[] = $number;
                }
            }
        }
        if (self::length($explicit) === 1) {
            $allNumbers = self::candidateNumbers($titleText);
            $extra = array_values(array_filter($allNumbers, static fn($number) => ($number !== $explicit[0])));
            if ($extra && self::containsAny($titleText, ['+', ' and ', '&', 'bundle', 'x2'])) {
                return '';
            }
            return $explicit[0];
        }
        $allNumbers = self::candidateNumbers($titleText);
        if (self::length($allNumbers) === 1) {
            return $allNumbers[0];
        }
        return '';
    }

    public static function legoModelKey(array $attrs, string $title = ''): ?string
    {
        $safeAttrs = ($attrs ?: []);
        $text = self::blob($safeAttrs, $title);
        if (self::isNotRealLego($text) || self::isBulkOrJoblot($text) || self::isPartialOrAccessory($text) || self::isSubBuildWithoutWholeSetSignal(self::clean($title))) {
            return self::UNKNOWN_KEY;
        }
        if (self::isMinifigureListing($safeAttrs, $title)) {
            return (self::parseMinifigId($text) ?: self::UNKNOWN_KEY);
        }
        $setNumber = (self::parseSetNumberFromAttrs($safeAttrs) ?: self::parseSetNumberFromTitle($title));
        if (!$setNumber) {
            return self::UNKNOWN_KEY;
        }
        return 'lego-set-' . $setNumber;
    }
}
