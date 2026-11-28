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

    /**
     * Seller prose, listing metadata and URLs are left out: descriptions list every compatible platform
     * and words such as "provide" or "product", which made headsets and base consoles look like a PS5 Pro.
     */
    private const NOISY_ATTR = '~^(?:_|trading (?:description|conditiondescription|imageurls|primarycategory)|'
        . 'browse (?:image|seller|shipping|itemlocation|itemcreation|itemend|category))|url~i';

    private static function attrBlob(array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $key => $value) {
            if (preg_match(self::NOISY_ATTR, (string)$key)) {
                continue;
            }
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
        if (self::search('\\b2\\s?(?:tb|terabytes?)\\b', $text)) {
            return '2tb';
        }
        if (self::search('\\b1\\s?(?:tb|terabytes?)\\b', $text)) {
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
                if (self::search('\\b(travel\\s+cases?|carrying\\s+cases?|carry\\s+cases?|protective\\s+cases?|hard\\s+cases?|memory\\s+cards?|usb\\s*-?\\s*cables?|controllers?|chargers?|headsets?|power\\s+adapters?|power\\s+supply)\\b', $text)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** "Dust Cover for Xbox Series X Console": what follows "for" is the platform it fits, not the item. */
    private static function itemPart(string $titleText): string
    {
        return preg_split('~\\b(?:for|designed\\s+for|compatible\\s+with|fits)\\b~u', $titleText, 2)[0];
    }

    private static function hasConsoleHardwareSignal(string $titleText, string $text): bool
    {
        $titleText = self::itemPart($titleText);
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
        ]) ?: (bool)((self::search('\\b(12|20|60|120|128|160|250|256|320|500|512|825)\\s?gb\\b|\\b(1|2)\\s?tb\\b', $titleText)
            // "Xbox Series X Bundle + Wired Controller". Other platforms' "bundle" is as often games as hardware.
            ?: self::search('\\bseries\\s*[xs]\\s+bundle\\b', $titleText)
            ?: self::search('\\bnintendo\\s+switch\\s+(lite|oled)\\b', $titleText) ?: self::search('\\b(?:with|including|incl\\.?)\\s+(?:a\\s+)?(?:console|system)\\b', $text))));
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
            '\\b(charger|cable|controller|gamepad|case|cover|shell|skin|sleeve|bag|dock|stand|mount|bracket|fan|cooler|remote|grip|game|software)\\b.{0,40}\\b(for|compatible with)\\b',
        ];
        return (!$hardwareSignal && self::any($accessoryPatterns, static fn($pattern) => self::search($pattern, $titleText)));
    }

    /**
     * Headsets, wheels, chargers and similar peripherals name every platform they work with. They are the
     * item unless a "with", "+", "plus" or "bundle" comes first, so "Xbox Series S With Headset" stays a
     * console while "Nintendo Switch & Switch Lite Car Charger" does not. "No charger" is not a peripheral.
     */
    private static function isPeripheral(string $titleText): bool
    {
        // Batteries, cameras and styluses are sold alone for every model, but sellers also list consoles with
        // an "Improved Battery" (the Switch V2) or a "Battery Pack". They are the item only when nothing before
        // them already names a console.
        $part = self::search('(?<!\\bimproved\\s)(?<!\\bextended\\s)\\b(?:batter(?:y|ies|ie)|\\d+\\s?mah|'
            . 'stylus(?:es)?|touch\\s+pens?|web-?cams?|cameras?|power\\s+banks?)\\b', $titleText);
        if ($part) {
            $before = mb_substr($titleText, 0, $part->start());
            if (!self::hasConsoleHardwareSignal($before, $before)
                && !self::search('\\b(?:with|plus|bundle|includ\\w*|incl?)\\b|\\bw/|\\+', $before)) {
                return true;
            }
        }
        $peripheral = self::search('(?<!\\bno\\s)(?<!\\bwithout\\s)\\b(?:headsets?|headphones?|earbuds?|earphones?|mixamp|'
            . 'racing\\s+wheel|steering\\s+wheel|usb\\s+hub|cooling\\s+fans?|charging\\s+(?:station|base|stand|dock)|'
            . 'keyboard|disc\\s+drive\\s+for|optical\\s+disc\\s+drive|chargers?|adapters?|hubs?|'
            . 'power\\s+(?:supply|supplies|suply|bricks?)|(?:storage\\s+)?expansion\\s+cards?)\\b'
            . '(?!\\s+(?:port|jack|socket|input))', $titleText);
        if (!$peripheral) {
            return false;
        }
        $before = mb_substr($titleText, 0, $peripheral->start());
        if (self::search('\\b(?:with|plus|bundle|includ\\w*|incl?|no)\\b|\\bw/|\\+', $before)) {
            return false;
        }
        // "Switch Lite Console and Charger", "Xbox 360 Console 250GB - Controller & Power Supply": the console
        // comes first and the peripheral is one of a list. "Xbox 360 Console 150W AC Power Adapter" is not.
        return !self::search('\\b(?:console|system|handheld|edition|\\d+\\s?(?:gb|tb))\\b.*(?:,|&|\\.\\s|\\s[-–|]\\s|\\band\\b|'
            . '\\b(?:controllers?|pads?|joy-?cons?|games?|dock)\\b)', $before);
    }

    /**
     * "Xbox Series X Black Ops 6 Console Wrap" is a wrap, not a console. A console sold with a skin on it
     * ("Home Console Camo Skin + Controller", "Console w/ Multicolour Skin") is still the console, so the
     * covering must be the item itself: straight after "console", or followed by "for" or "decal".
     */
    private static function isCovering(string $titleText): bool
    {
        $covering = '(?:skins?|wraps?|decals?|stickers?|vinyls?)';
        $match = self::search('\\b(?:consoles?|controllers?)\\s+' . $covering . '\\b'
            . '|\\b' . $covering . '\\s+(?:for|decal|sticker|set|kit)\\b', $titleText);
        if (!$match) {
            return false;
        }
        $before = mb_substr($titleText, 0, $match->start());
        return !self::search('\\b(?:with|plus|includ\\w*|incl?)\\b|\\bw/|\\+', $before);
    }

    private static function isAccessoryOrPart(string $text, bool $hardwareSignal = false): bool
    {
        $explicitAccessoryOrPart = self::containsAny($text, [
            'box only',
            'case only',
            'no console',
            'empty box',
            'insert only',
            'inserts only',
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
            'mini fridge',
            'hard drive for',
            'hdd for',
            'replacement hard drive',
            'internal hard drive',
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
            'wired controller',
            'joy-con controller',
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
        ]) ?: (bool)((self::search('\\b(12|20|60|120|160|250|320|500|512|825)\\s?gb\\b|\\b(1|2)\\s?tb\\b', $text) ?: self::search('\\bnintendo\\s+switch\\s+(lite|oled)\\b', $text) ?: self::search('\\bseries\\s*[xs]\\s+bundle\\b', $text))));
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

    /** "Pro" must name the console itself ("PS5 Pro"), not a "Pro Controller" or a word like "provide". */
    private static function isProModel(string $titleText, array $attrs, string $generation): bool
    {
        $pattern = '\\b(?:ps\\s*' . $generation . '|playstation\\s*' . $generation . ')\\s*(?:console\\s+)?pro\\b';
        if (self::search($pattern, $titleText)) {
            return true;
        }
        $model = ($attrs['Model'] ?? $attrs['model_name'] ?? null);
        foreach (is_array($model) ? $model : [$model] as $item) {
            if (self::search($pattern, self::asText($item))) {
                return true;
            }
        }
        return false;
    }

    private static function playstationKey(array $attrs, string $titleText, string $text, string $storage): string
    {
        $vita = (bool)self::search('\\bvita\\b', $text);
        if (!$vita && !self::containsAny($text, ['playstation', 'ps5', 'ps4', 'ps3'])) {
            return self::UNKNOWN_KEY;
        }
        if (self::search('\\b(playstation\\s*1|ps1|ps\\s*one)\\b', $titleText)) {
            return self::UNKNOWN_KEY;
        }
        if (self::search('\\b(playstation\\s*2|ps2)\\b', $titleText)) {
            return self::UNKNOWN_KEY;
        }
        // A whole word, so "PlayVital" accessories are not a PS Vita.
        if ($vita) {
            return 'sony-playstation-vita';
        }
        if ((str_contains($text, 'ps3')) || (str_contains($text, 'playstation 3'))) {
            return 'sony-playstation-3';
        }
        if ((str_contains($text, 'ps5')) || (str_contains($text, 'playstation 5'))) {
            if (self::isProModel($titleText, $attrs, '5')) {
                return 'sony-playstation-5-pro-2tb';
            }
            $slim = (str_contains($text, 'slim'));
            $digital = (str_contains($text, 'digital'));
            $disc = self::containsAny($text, ['disc edition', 'disk edition', 'disc console', 'disk console',
                'disc version', 'blu ray', 'blu-ray']);
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
            if (self::isProModel($titleText, $attrs, '4')) {
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

    /** "Series X|S", "Series X/S" and "X & S" name the platform family, not the console being sold. */
    private static function withoutFamilyName(string $text): string
    {
        return preg_replace('~\\b((?:series|one)\\s*)?x\\s*(?:\\||/|&|and)\\s*s\\b~iu', ' ', $text) ?? $text;
    }

    private static function xboxSeriesVariant(string $titleText, string $text): string
    {
        foreach (array_map(self::withoutFamilyName(...), [$titleText, $text]) as $candidate) {
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
        foreach (array_map(self::withoutFamilyName(...), [$titleText, $text]) as $candidate) {
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

    /** A size can be assumed only when the title is the console, not a game or an accessory that names it. */
    private static function namesConsoleItself(string $titleText): bool
    {
        return self::hasConsoleHardwareSignal($titleText, $titleText)
            && !self::search('\\bvideo\\s+game\\b|\\bgame\\s+(?:disc|disk)\\b', $titleText);
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
            $variant = self::xboxSeriesVariant($titleText, $text);
            if (!$variant) {
                return self::UNKNOWN_KEY;
            }
            // eBay catalogue titles leave out the drive. A Series X is 1TB unless it says 2TB; a Series S is
            // 512GB unless it is the 1TB Carbon Black. Without a size, the title must still name a console.
            if (!$storage && self::namesConsoleItself($titleText)) {
                $storage = ($variant === 'x' ? '1tb' : (str_contains($titleText, 'carbon black') ? '1tb' : '512gb'));
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
            $variant = self::xboxOneVariant($titleText, $text);
            // The One X only shipped with 1TB; the One and One S came in several sizes.
            if (!$storage && $variant === 'x' && self::namesConsoleItself($titleText)) {
                $storage = '1tb';
            }
            if (!$storage) {
                return self::UNKNOWN_KEY;
            }
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
        // "Toggle switch", "transfer switch" and "switchless" are not Nintendo hardware.
        if (!self::search('\\bswitch\\b', $text) || !str_contains($text, 'nintendo')) {
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

    /** eBay's Video Game Consoles category. */
    private const CONSOLE_CATEGORY = '139971';

    /**
     * Enriched listings carry eBay's own category. One outside Video Game Consoles is an accessory, part or
     * game whatever its title names, such as a Switch Lite battery or a Switch OLED screen protector.
     */
    private static function inOtherCategory(array $attrs): bool
    {
        foreach (['Browse categoryId', 'Trading PrimaryCategoryID'] as $key) {
            $id = $attrs[$key] ?? null;
            if (is_scalar($id) && trim((string)$id) !== '') {
                return trim((string)$id) !== self::CONSOLE_CATEGORY;
            }
        }
        return false;
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
        // Accessories get keys of their own, so they are valued against each other and never as the console.
        if (self::isPeripheral($titleText) || self::isCovering($titleText)
            || self::looksLikeAccessoryOrSoftwareIntent($titleText, $text, $attrs)
            || self::isAccessoryOrPart($text, hardwareSignal: $hardwareSignal)
        ) {
            return ConsoleAccessories::key($title);
        }
        // A listing outside eBay's consoles category is an accessory, part or game whatever its title names.
        // One whose title still reads as a console (a seller's wrong category) is left unkeyed.
        if (self::inOtherCategory($attrs)) {
            return self::hasConsoleHardwareSignal($titleText, $titleText)
                ? self::UNKNOWN_KEY : ConsoleAccessories::key($title);
        }
        if (self::looksLikeGameOnly($text, $attrs)) {
            // "Xbox One Controllers" names a platform and no console, like a game title does.
            return self::hasGameAttrs($attrs) ? self::UNKNOWN_KEY : ConsoleAccessories::key($title, unmistakable: true);
        }
        $storage = self::extractStorage($attrs, $title);
        $key = self::playstationKey($attrs, $titleText, $text, $storage);
        if ($key !== self::UNKNOWN_KEY) {
            return $key;
        }
        $key = self::xboxKey($titleText, $text, $storage);
        if ($key !== self::UNKNOWN_KEY) {
            return $key;
        }
        $key = self::switchKey($attrs, $titleText, $text);
        // "Xbox One Controllers" names no console and none of the phrases above: it may still be an accessory.
        if ($key === self::UNKNOWN_KEY && !self::hasConsoleHardwareSignal($titleText, $titleText)) {
            return ConsoleAccessories::key($title);
        }
        return $key;
    }
}
