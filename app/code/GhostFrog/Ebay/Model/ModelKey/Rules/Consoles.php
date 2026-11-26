<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey\Rules;

final class Consoles extends Rule
{
    private static function asText($val): string
    {
        if ($val === null) {
            return '';
        }
        return mb_strtolower(trim(self::stringify($val)));
    }

    private static function attrBlob(array $attrs): string
    {
        $parts = [];
        foreach (array_values($attrs) as $value) {
            if (is_array($value)) {
                array_push($parts, ...array_map(static fn($item) => self::asText($item), self::iterate($value)));
            } else {
                $parts[] = self::asText($value);
            }
        }
        return implode(' ', array_values(array_filter($parts, static fn($part) => $part)));
    }

    private static function blob(array $attrs, string $title): string
    {
        return trim(self::asText($title) . ' ' . self::attrBlob($attrs));
    }

    private static function extractStorage(array $attrs, string $title): string
    {
        $attrStorage = implode(' ', array_map(static fn($key) => self::asText(($attrs[$key] ?? null)), ['Storage Capacity', 'Storage', 'Size', 'Hard Drive Capacity', 'storage_gb']));
        $text = $attrStorage . ' ' . self::asText($title);
        if (self::search('\\b2\\s?tb\\b', $text)) {
            return '2tb';
        }
        if (self::search('\\b1\\s?tb\\b', $text)) {
            return '1tb';
        }
        if (self::search('\\b825\\s?gb\\b', $text)) {
            return '825gb';
        }
        if (self::search('\\b512\\s?gb\\b', $text)) {
            return '512gb';
        }
        if (self::search('\\b500\\s?gb\\b', $text)) {
            return '500gb';
        }
        $legacyMatch = self::search('\\b(320|250|120|60|40|20|12)\\s?gb\\b', $text);
        if ($legacyMatch) {
            return $legacyMatch->group(1) . 'gb';
        }
        return '';
    }

    private static function hasGameAttrs(array $attrs): bool
    {
        return self::any(['Game Name', 'Video Game Name', 'Genre', 'Rating'], static fn($key) => (in_array($key, $attrs, true)));
    }

    private static function hasConsoleAccessoryTypeAttrs(array $attrs): bool
    {
        $accessoryTypeKeys = [
            'Type',
            'Product Type',
            'Accessory Type',
            'Browse aspect Type',
            'Browse aspect Product Type',
            'Browse aspect Accessory Type',
        ];
        foreach ($accessoryTypeKeys as $key) {
            $value = ($attrs[$key] ?? null);
            $values = (is_array($value) ? $value : [$value]);
            foreach (self::iterate($values) as $item) {
                $text = self::asText($item);
                if (self::search('\\b(travel\\s+cases?|carrying\\s+cases?|carry\\s+cases?|protective\\s+cases?|hard\\s+cases?|memory\\s+cards?|usb\\s*-?\\s*cables?|controllers?)\\b', $text)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function hasConsoleHardwareSignal(string $titleText, string $text): bool
    {
        return (self::containsAny($titleText, [
            'console',
            'system',
            'handheld',
            'disc edition',
            'disk edition',
            'digital edition',
            'blu ray',
            'blu-ray',
            'tablet only',
            'oled model',
        ]) ?: (bool)((self::search('\\b(128|256|500|512|825)\\s?gb\\b|\\b(1|2)\\s?tb\\b', $titleText) ?: self::search('\\bnintendo\\s+switch\\s+(lite|oled)\\b', $titleText) ?: self::search('\\b(?:with|including|incl\\.?)\\s+(?:a\\s+)?(?:console|system)\\b', $text))));
    }

    private static function looksLikeAccessoryOrSoftwareIntent(string $titleText, string $text, array $attrs): bool
    {
        $attrKeys = implode(' ', array_map(static fn($key) => self::asText($key), array_keys($attrs)));
        if (self::containsAny($attrKeys, ['game name', 'video game name', 'genre', 'rating'])) {
            return true;
        }
        if (self::hasConsoleAccessoryTypeAttrs($attrs)) {
            return true;
        }
        $hardwareSignal = self::hasConsoleHardwareSignal($titleText, $text);
        $accessoryPatterns = [
            '\\b(wall\\s+charger|usb\\s*-?\\s*c\\s+charger|charger\\s+cable|charging\\s+cable|usb\\s*-?\\s*c\\s+cable)\\b',
            '\\b(wireless\\s+controller|pro\\s+controller|controller\\s+for|gamepad\\s+for)\\b',
            '\\b(carrying\\s+case|protective\\s+case|messenger\\s+bag|travel\\s+bag|case\\s+for|bag\\s+for)\\b',
            '\\b(dock\\s+only|dock\\s+for|screen\\s+protector|sd\\s+card|memory\\s+card|stand\\s+for|grip\\s+for)\\b',
            '\\b(replacement\\s+part|replacement\\s+shell|replacement\\s+case)\\b',
            '\\b(console\\s+game|game\\s+cartridge|game\\s+card|video\\s+game|software\\s+only)\\b',
            '\\b(charger|cable|controller|gamepad|case|bag|dock|stand|grip|game|software)\\b.{0,40}\\b(for|compatible with)\\b',
        ];
        return (!$hardwareSignal && self::any($accessoryPatterns, static fn($pattern) => self::search($pattern, $titleText)));
    }

    private static function isAccessoryOrPart(string $text, bool $hardwareSignal = false): bool
    {
        $explicitAccessoryOrPart = self::containsAny($text, [
            'box only',
            'case only',
            'no console',
            'empty box',
            'packaging only',
            'controller only',
            'controller pair',
            'controller pairs',
            'dock only',
            'accessory kit',
            'accessories kit',
            'sports accessories',
            'replacement shell',
            'replacement case',
            'housing shell',
            'faceplate',
            'cover plate',
            'thumbstick',
            'joystick',
            'hdmi port',
            'battery for',
            'battery replacement',
            'replacement battery',
            'hac-003',
            'charging cable',
            'skin sticker',
            'guide button',
            'led sticker',
            'playstation portal',
            'remote player',
            'cronus zen',
            'joycons bundle',
            'joy-con bundle',
            'joycon only',
            'joy-cons only',
            'joycon pair',
            'joy-con pair',
            'joy-con controller',
            'disc drive attachment',
            'disc drive blu ray attachment',
            'game only',
            'console game',
            'game cartridge',
            'game card',
            'software only',
            'code in a box',
            'download code',
            'digital code',
        ]);
        if ($explicitAccessoryOrPart) {
            return true;
        }
        $accessoryUnlessHardware = self::containsAny($text, [
            'wireless controller',
            'power a controller',
            'wall charger',
            'usb-c charger',
            'usb c charger',
            'charging dock',
            'power supply',
            'messenger bag',
            'screen protector',
            'sd card',
            'memory card',
            'video game',
        ]);
        return ($accessoryUnlessHardware && !$hardwareSignal);
    }

    private static function looksLikeGameOnly(string $text, array $attrs): bool
    {
        if (self::hasGameAttrs($attrs)) {
            return true;
        }
        if (self::containsAny($text, [
            'cartridge',
            'steelbook',
            'artwork',
            'cover is',
            'pal english',
            'game for',
            'ps5 game',
            'ps4 game',
            'ps3 game',
            'xbox game',
            'switch game',
            'nintendo switch game',
        ])) {
            return true;
        }
        $hardwareSignal = (self::containsAny($text, [
            'console',
            'system',
            'handheld',
            'disc edition',
            'disk edition',
            'digital edition',
            'blu ray',
            'blu-ray',
            'with controller',
            'console only',
            'tablet only',
            'oled model',
        ]) ?: (bool)((self::search('\\b(500|512|825)\\s?gb\\b|\\b(1|2)\\s?tb\\b', $text) ?: self::search('\\bnintendo\\s+switch\\s+(lite|oled)\\b', $text))));
        $platformOnly = self::containsAny($text, [
            'playstation 5',
            'playstation 4',
            'playstation 3',
            'playstation vita',
            'ps5',
            'ps4',
            'ps3',
            'ps vita',
            'xbox one',
            'xbox series',
            'nintendo switch',
        ]);
        return ($platformOnly && !$hardwareSignal);
    }

    private static function playstationKey(string $titleText, string $text, string $storage): string
    {
        if (!self::containsAny($text, ['playstation', 'ps5', 'ps4', 'ps3', 'vita'])) {
            return self::UNKNOWN_KEY;
        }
        if (self::search('\\b(playstation\\s*1|ps1|ps\\s*one)\\b', $titleText)) {
            return self::UNKNOWN_KEY;
        }
        if (self::search('\\b(playstation\\s*2|ps2)\\b', $titleText)) {
            return self::UNKNOWN_KEY;
        }
        if ((str_contains($text, 'vita')) || (str_contains($text, 'ps vita')) || (str_contains($text, 'playstation vita'))) {
            return 'sony-playstation-vita';
        }
        if ((str_contains($text, 'ps3')) || (str_contains($text, 'playstation 3'))) {
            return 'sony-playstation-3';
        }
        if ((str_contains($text, 'ps5')) || (str_contains($text, 'playstation 5'))) {
            if (str_contains($text, 'pro')) {
                return 'sony-playstation-5-pro-2tb';
            }
            $slim = (str_contains($text, 'slim'));
            $digital = (str_contains($text, 'digital'));
            $disc = self::containsAny($text, ['disc edition', 'disk edition', 'blu ray', 'blu-ray']);
            if (!$storage || !($digital || $disc)) {
                return self::UNKNOWN_KEY;
            }
            if ($slim && ($storage !== '1tb')) {
                return self::UNKNOWN_KEY;
            }
            if (!$slim && ($storage !== '825gb')) {
                return self::UNKNOWN_KEY;
            }
            $edition = ($digital ? 'digital' : 'disc');
            if ($slim) {
                return 'sony-playstation-5-slim-' . $edition . '-' . $storage;
            }
            return 'sony-playstation-5-' . $edition . '-' . $storage;
        }
        if ((str_contains($text, 'ps4')) || (str_contains($text, 'playstation 4'))) {
            if (!$storage) {
                return self::UNKNOWN_KEY;
            }
            if (str_contains($text, 'pro')) {
                $variant = 'pro';
            } else {
                if (str_contains($text, 'slim')) {
                    $variant = 'slim';
                } else {
                    $variant = 'base';
                }
            }
            if (!in_array($storage, ['500gb', '1tb', '2tb'], true)) {
                return self::UNKNOWN_KEY;
            }
            return 'sony-playstation-4-' . $variant . '-' . $storage;
        }
        return self::UNKNOWN_KEY;
    }

    private static function xboxSeriesVariant(string $titleText, string $text): string
    {
        foreach ([$titleText, $text] as $candidate) {
            $seriesX = (bool)(self::search('\\bseries\\s*x\\b|\\bxbox\\s*x\\b', $candidate));
            $seriesS = (bool)(self::search('\\bseries\\s*s\\b|\\bxbox\\s*s\\b', $candidate));
            if ($seriesX && !$seriesS) {
                return 'x';
            }
            if ($seriesS && !$seriesX) {
                return 's';
            }
        }
        return '';
    }

    private static function xboxOneVariant(string $titleText, string $text): string
    {
        foreach ([$titleText, $text] as $candidate) {
            $oneX = (bool)(self::search('\\bone\\s*x\\b', $candidate));
            $oneS = (bool)(self::search('\\bone\\s*s\\b', $candidate));
            if ($oneX && !$oneS) {
                return 'x';
            }
            if ($oneS && !$oneX) {
                return 's';
            }
        }
        return 'base';
    }

    private static function xboxKey(string $titleText, string $text, string $storage): string
    {
        if (!str_contains($text, 'xbox')) {
            return self::UNKNOWN_KEY;
        }
        if (str_contains($text, '360')) {
            if ($storage) {
                return 'microsoft-xbox-360-' . $storage;
            }
            return 'microsoft-xbox-360';
        }
        if (str_contains($text, 'series')) {
            if (!$storage) {
                return self::UNKNOWN_KEY;
            }
            $variant = self::xboxSeriesVariant($titleText, $text);
            if (!$variant) {
                return self::UNKNOWN_KEY;
            }
            if (($variant === 'x') && (!in_array($storage, ['1tb', '2tb'], true))) {
                return self::UNKNOWN_KEY;
            }
            if (($variant === 's') && (!in_array($storage, ['512gb', '1tb'], true))) {
                return self::UNKNOWN_KEY;
            }
            return 'microsoft-xbox-series-' . $variant . '-' . $storage;
        }
        if (str_contains($text, 'one')) {
            if (!$storage) {
                return self::UNKNOWN_KEY;
            }
            $variant = self::xboxOneVariant($titleText, $text);
            if (!in_array($storage, ['500gb', '1tb', '2tb'], true)) {
                return self::UNKNOWN_KEY;
            }
            return 'microsoft-xbox-one-' . $variant . '-' . $storage;
        }
        return self::UNKNOWN_KEY;
    }

    private static function switch2StructuredSignal(array $attrs): bool
    {
        foreach ([
            'Model',
            'Platform',
            'Compatible Product',
            'Browse aspect Model',
            'Browse aspect Platform',
            'Browse aspect Compatible Product',
            'model_name',
            'platform',
        ] as $key) {
            $value = ($attrs[$key] ?? null);
            $values = (is_array($value) ? $value : [$value]);
            foreach (self::iterate($values) as $item) {
                $text = self::asText($item);
                if (self::search('\\b(?:nintendo\\s+)?switch\\s*2\\b', $text)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function hasSwitch2TitleSignal(string $titleText): bool
    {
        return (bool)(self::search('\\b(?:nintendo\\s+)?switch\\s*2\\b', $titleText));
    }

    private static function switchKey(array $attrs, string $titleText, string $text): string
    {
        if (!str_contains($text, 'switch')) {
            return self::UNKNOWN_KEY;
        }
        if ((str_contains($text, 'tablet only')) || (str_contains($text, 'console/tablet only')) || (str_contains($text, 'screen only'))) {
            return 'nintendo-switch-tablet-only';
        }
        if (str_contains($text, 'oled')) {
            return 'nintendo-switch-oled';
        }
        if (str_contains($text, 'lite')) {
            return 'nintendo-switch-lite';
        }
        if (self::hasSwitch2TitleSignal($titleText) || self::switch2StructuredSignal($attrs)) {
            return 'nintendo-switch-2';
        }
        return 'nintendo-switch-base';
    }

    public static function consoleOrGameModelKey(array $attrs, string $title): ?string
    {
        $game = Games::key($attrs, $title);
        if ($game !== self::UNKNOWN_KEY) {
            return $game;
        }
        $titleText = self::asText($title);
        $text = self::blob($attrs, $title);
        $hardwareSignal = self::hasConsoleHardwareSignal($titleText, $text);
        if (self::looksLikeAccessoryOrSoftwareIntent($titleText, $text, $attrs) || self::isAccessoryOrPart($text, hardwareSignal: $hardwareSignal) || self::looksLikeGameOnly($text, $attrs)) {
            return self::UNKNOWN_KEY;
        }
        $storage = self::extractStorage($attrs, $title);
        $key = self::playstationKey($titleText, $text, $storage);
        if ($key !== self::UNKNOWN_KEY) {
            return $key;
        }
        $key = self::xboxKey($titleText, $text, $storage);
        if ($key !== self::UNKNOWN_KEY) {
            return $key;
        }
        return self::switchKey($attrs, $titleText, $text);
    }
}
