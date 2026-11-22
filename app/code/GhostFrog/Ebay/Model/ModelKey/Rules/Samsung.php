<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey\Rules;

final class Samsung extends Rule
{
    private static function text($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            return implode(' ', array_map(static fn($item) => self::text($item), self::iterate($value)));
        }
        return mb_strtolower(trim(self::stringify($value)));
    }

    private static function cleanToken(string $value): string
    {
        return trim(self::replacePattern('[^a-z0-9]+', '-', mb_strtolower($value)), '-');
    }

    private static function blob(array $attrs, string $title): string
    {
        $fields = [
            $title,
            self::text(($attrs['Brand'] ?? null)),
            self::text(($attrs['Model'] ?? null)),
            self::text(($attrs['Model Number'] ?? null)),
            self::text(($attrs['MPN'] ?? null)),
            self::text(($attrs['Product Line'] ?? null)),
            self::text(($attrs['Series'] ?? null)),
            self::text(($attrs['Network'] ?? null)),
            self::text(($attrs['Connectivity'] ?? null)),
            self::text(($attrs['Cellular Band'] ?? null)),
            self::text(($attrs['Cellular Technology'] ?? null)),
            self::text(($attrs['Storage Capacity'] ?? null)),
            self::text(($attrs['Size'] ?? null)),
        ];
        return mb_strtolower(implode(' ', array_values(array_filter($fields, static fn($part) => $part))));
    }

    private static function isSamsung(array $attrs, string $title): bool
    {
        $brand = self::text((($attrs['Brand'] ?? null) ?: ($attrs['brand'] ?? null)));
        $blob = self::blob($attrs, $title);
        return ((str_contains($brand, 'samsung')) || (self::contains($blob, 'samsung')) || (self::contains($blob, 'galaxy')));
    }

    private static function isJunk(string $blob): bool
    {
        $junkTerms = [
            'case for',
            'phone case',
            'flip cover',
            'screen protector',
            'tempered glass',
            'replacement screen',
            'screen assembly',
            'lcd assembly',
            'digitizer',
            'charging port',
            'flex cable',
            'back cover',
            'back glass',
            'housing',
            'frame',
            'battery door',
            'charger only',
            'cable only',
            'box only',
            'empty box',
            'dummy phone',
            'display phone',
        ];
        return self::containsAny($blob, $junkTerms);
    }

    private static function capacityToken(string $amount, string $unit): string
    {
        $number = intval($amount);
        $unit = mb_strtolower($unit);
        if ($unit === 'tb') {
            if ($number === 1) {
                return '1tb';
            }
            return $number . 'tb';
        }
        if (in_array($number, [250, 251, 255, 256], true)) {
            return '256gb';
        }
        if (in_array($number, [500, 512], true)) {
            return '512gb';
        }
        if (in_array($number, [1000, 1024], true)) {
            return '1tb';
        }
        return $number . 'gb';
    }

    private static function storageCandidates(string $text): array
    {
        $candidates = [];
        foreach (self::findMatches('\\b(32|64|128|256|512|1000|1024|1)\\s*(gb|tb)\\b', $text) as $match) {
            $before = mb_substr($text, max(0, ($match->start() - 18)), ($match->start() - max(0, ($match->start() - 18))));
            $after = mb_substr($text, $match->end(), (($match->end() + 18) - $match->end()));
            if (self::search('(ram|memory)\\s*$', $before) || self::search('^\\s*(ram|memory)', $after)) {
                continue;
            }
            $token = self::capacityToken($match->group(1), $match->group(2));
            if (!in_array($token, $candidates, true)) {
                $candidates[] = $token;
            }
        }
        return $candidates;
    }

    private static function singleStorage(array $candidates): string
    {
        return ((self::length($candidates) === 1) ? $candidates[0] : '');
    }

    private static function storage(array $attrs, string $title): string
    {
        $titleStorage = self::singleStorage(self::storageCandidates(mb_strtolower($title)));
        $storageText = implode(' ', array_map(static fn($key) => self::text(($attrs[$key] ?? null)), array_values(array_filter(['Storage Capacity', 'Size', 'Memory Card Type', 'Capacity', 'Model'], static fn($key) => ($attrs[$key] ?? null)))));
        $attrStorage = self::singleStorage(self::storageCandidates($storageText));
        if ($titleStorage && $attrStorage && ($titleStorage !== $attrStorage)) {
            return '';
        }
        return ($titleStorage ?: $attrStorage);
    }

    private static function has5g(array $attrs, string $title): bool
    {
        return (bool)(self::search('\\b5g\\b', self::blob($attrs, $title)));
    }

    private static function foldableFromModelNumber(string $blob): string
    {
        if (self::search('\\bsm-f9(?:16|26|36|46|56|66)[a-z]*\\b', $blob)) {
            $mapping = [
                '916' => 'z-fold2',
                '926' => 'z-fold3',
                '936' => 'z-fold4',
                '946' => 'z-fold5',
                '956' => 'z-fold6',
                '966' => 'z-fold7',
            ];
            $code = self::search('\\bsm-f9(16|26|36|46|56|66)[a-z]*\\b', $blob);
            return ($code ? ($mapping[$code->group(1)] ?? '') : '');
        }
        if (self::search('\\bsm-f7(?:11|21|31|41|61)[a-z]*\\b', $blob)) {
            $mapping = ['11' => 'z-flip3', '21' => 'z-flip4', '31' => 'z-flip5', '41' => 'z-flip6', '61' => 'z-flip7'];
            $code = self::search('\\bsm-f7(11|21|31|41|61)[a-z]*\\b', $blob);
            return ($code ? ($mapping[$code->group(1)] ?? '') : '');
        }
        return '';
    }

    private static function extractFoldable(string $blob): string
    {
        $modelNumber = self::foldableFromModelNumber($blob);
        if ($modelNumber) {
            return $modelNumber;
        }
        $match = self::search('\\b(?:galaxy\\s*)?z\\s*fold\\s*(\\d)\\b', $blob);
        if ($match) {
            return 'z-fold' . $match->group(1);
        }
        $match = self::search('\\b(?:galaxy\\s*)?z\\s*flip\\s*(\\d)\\b', $blob);
        if ($match) {
            return 'z-flip' . $match->group(1);
        }
        if (self::search('\\bz\\s*fold\\b|\\bfold\\b', $blob)) {
            return '';
        }
        if (self::search('\\bz\\s*flip\\b|\\bflip\\b', $blob)) {
            return '';
        }
        return '';
    }

    private static function extractSOrNote(string $blob): string
    {
        $blob = self::replacePattern('\\bs(\\d{1,2})\\+', 's\\1 plus', $blob);
        $blob = self::replacePattern('\\bnote(\\d{1,2})\\+', 'note\\1 plus', $blob);
        $match = self::search('\\b(?:galaxy\\s*)?s\\s*(\\d{1,2})\\s*(ultra|fe|plus|\\+)?\\b', $blob);
        if (!$match) {
            $match = self::search('\\bs(\\d{1,2})(ultra|fe|plus|\\+)?\\b', $blob);
        }
        if ($match) {
            $generation = $match->group(1);
            $tier = ($match->group(2) ?: '');
            if (!$tier) {
                $tail = mb_substr($blob, $match->end(), (($match->end() + 36) - $match->end()));
                $tierMatch = self::search('\\b(ultra|fe|plus|\\+)\\b', $tail);
                $tier = ($tierMatch ? $tierMatch->group(1) : '');
            }
            $tier = (($tier === '+') ? 'plus' : $tier);
            return implode('-', array_values(array_filter(['s' . $generation, $tier], static fn($part) => $part)));
        }
        $match = self::search('\\b(?:galaxy\\s*)?note\\s*(\\d{1,2})\\s*(ultra|plus|\\+)?\\b', $blob);
        if (!$match) {
            $match = self::search('\\bnote(\\d{1,2})(ultra|plus|\\+)?\\b', $blob);
        }
        if ($match) {
            $tier = (($match->group(2) === '+') ? 'plus' : ($match->group(2) ?: ''));
            return implode('-', array_values(array_filter(['note' . $match->group(1), $tier], static fn($part) => $part)));
        }
        return '';
    }

    private static function extractBudgetPhone(string $blob): string
    {
        $match = self::search('\\b(?:galaxy\\s*)?([am])\\s*(\\d{2})(s)?\\b', $blob);
        if ($match) {
            $suffix = ($match->group(3) ? 's' : '');
            return $match->group(1) . $match->group(2) . $suffix;
        }
        $match = self::search('\\b([am]\\d{2}s?)\\b', $blob);
        return ($match ? $match->group(1) : '');
    }

    private static function extractTab(string $blob): string
    {
        if (!self::search('\\b(tab|tablet)\\b', $blob)) {
            return '';
        }
        $line = '';
        $match = self::search('\\btab\\s*([as])\\s*(\\d{1,2})\\s*(ultra|plus|\\+|fe)?\\b', $blob);
        if ($match) {
            $tier = (($match->group(3) === '+') ? 'plus' : ($match->group(3) ?: ''));
            $line = implode('-', array_values(array_filter(['tab-' . $match->group(1) . $match->group(2), $tier], static fn($part) => $part)));
        } else {
            $match = self::search('\\bgalaxy\\s*tab\\s*([as])(\\d{1,2})(ultra|plus|\\+|fe)?\\b', $blob);
            if ($match) {
                $tier = (($match->group(3) === '+') ? 'plus' : ($match->group(3) ?: ''));
                $line = implode('-', array_values(array_filter(['tab-' . $match->group(1) . $match->group(2), $tier], static fn($part) => $part)));
            }
        }
        return $line;
    }

    private static function connectivityForModel(string $model, array $attrs, string $title): string
    {
        if (!self::has5g($attrs, $title)) {
            return '';
        }
        if (self::search('\A(?:' . '^[am]\\d{2}s?$' . ')', $model)) {
            return '5g';
        }
        return '';
    }

    private static function wifiOrCellular(array $attrs, string $title): string
    {
        $blob = self::blob($attrs, $title);
        if (self::search('\\b(cellular|5g|lte|4g|sim)\\b', $blob)) {
            return 'cellular';
        }
        if (self::search('\\b(wifi|wi-fi)\\b', $blob)) {
            return 'wifi';
        }
        return '';
    }

    /**
     * Build strict Samsung product identity only.
     *
     * Condition is stored separately in auction_listings.condition_grade.
     * Unclear Samsung rows return `unknown` rather than broad comp buckets.
     */
    public static function samsungModelKey(array $attrs, string $title = ''): ?string
    {
        if (!self::isSamsung($attrs, $title)) {
            return self::UNKNOWN_KEY;
        }
        $blob = self::blob($attrs, $title);
        if (self::isJunk($blob)) {
            return self::UNKNOWN_KEY;
        }
        $storage = self::storage($attrs, $title);
        $tab = self::extractTab($blob);
        if ($tab) {
            $connectivity = self::wifiOrCellular($attrs, $title);
            if (!$storage) {
                return self::UNKNOWN_KEY;
            }
            return implode('-', array_values(array_filter(['samsung', 'galaxy', $tab, $storage, $connectivity], static fn($part) => $part)));
        }
        $model = (self::extractFoldable($blob) ?: self::extractSOrNote($blob) ?: self::extractBudgetPhone($blob));
        if (!$model || !$storage) {
            return self::UNKNOWN_KEY;
        }
        $connectivity = self::connectivityForModel($model, $attrs, $title);
        return implode('-', array_values(array_filter(['samsung', 'galaxy', $model, $storage, $connectivity], static fn($part) => $part)));
    }
}
