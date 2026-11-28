<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey\Rules;

/**
 * Keys a console accessory by the platform it fits and what it is, so it is valued against the same kind
 * of accessory and never against the console: "accessory-ps5-controller-official",
 * "accessory-xbox-series-skin". An accessory whose platform or kind is unclear, or a listing of several,
 * is "unknown-accessory": it stays out of comps but is still marked as an accessory.
 */
final class ConsoleAccessories extends Rule
{
    public const PREFIX = 'accessory-';
    public const UNKNOWN = 'unknown-accessory';

    /** Platform families, matched in title order: the first one named is the one it was made for. */
    private const PLATFORMS = [
        'ps5' => '\\bps\\s*5\\b|\\bplaystation\\s*5\\b|\\bdual\\s*sense\\b|\\bplaystation\\s+portal\\b',
        'ps4' => '\\bps\\s*4\\b|\\bplaystation\\s*4\\b|\\bdual\\s*shock\\s*4\\b|\\bds4\\b',
        'ps3' => '\\bps\\s*3\\b|\\bplaystation\\s*3\\b|\\bdual\\s*shock\\s*3\\b|\\bsixaxis\\b',
        'ps-vita' => '\\bvita\\b',
        'xbox-series' => '\\bxbox\\s*series\\b|\\bseries\\s*[xs]\\b',
        'xbox-one' => '\\bxbox\\s*(?:one|1)\\b|\\bxboxone\\b',
        'xbox-360' => '\\bxbox\\s*360\\b|\\b360\\b',
        'xbox' => '\\bxbox\\b',
        'switch-2' => '\\bswitch\\s*2\\b',
        'switch' => '\\bswitch\\b|\\bjoy-?\\s?cons?\\b',
    ];

    /**
     * Accessory kinds, first match wins. Grips, stations and chargers come before the controllers they hold
     * or charge, and a wheel before the "Elite" in a wheel's name.
     */
    private const TYPES = [
        'portal' => '\\bplaystation\\s+portal\\b|\\bps\\s+portal\\b|\\bremote\\s+player\\b',
        'vr' => '\\bps\\s*vr\\s*2?\\b|\\bvr\\s*2?\\s+headset\\b|\\bvr2\\b',
        'skin' => '\\b(?:skins?|wraps?|decals?|stickers?|vinyls?)\\b',
        'charging-station' => '\\bcharging\\s+(?:station|dock|stand|base)\\b|\\bcharger\\s+(?:dock|station|stand)\\b'
            . '|\\bdual\\s+charg\\w*',
        'disc-drive' => '\\bdis[ck]\\s+drive\\b',
        'wheel' => '\\b(?:racing|steering)\\s+wheel\\b',
        'controller-elite' => '\\belite\\b.*\\b(?:controller|game\\s*pad|pad)\\b|\\b(?:controller|pad)\\b.*\\belite\\b',
        'controller-edge' => '\\bdual\\s*sense\\s+edge\\b|\\bedge\\s+(?:wireless\\s+)?controller\\b',
        'grip' => '\\bgrips?\\b|\\bstraps?\\b|\\battachments?\\b',
        'pro-controller' => '\\bpro\\s+controller\\b',
        'joy-con' => '\\bjoy-?\\s?cons?\\b',
        'headset' => '\\bheadsets?\\b|\\bheadphones?\\b|\\bearbuds?\\b|\\bearphones?\\b',
        'power' => '\\bpower\\s+(?:supply|supplies|brick|adapt[eo]r|cable|lead|cord|unit)\\b|\\bpsu\\b|\\bac\\s+adapt[eo]r\\b'
            . '|\\bmains\\s+(?:lead|cable)\\b|\\bkettle\\s+lead\\b|\\bfigure\\s*(?:8|eight)\\b',
        'hdmi-cable' => '\\bhdmi\\s+(?:cable|lead|cord)\\b',
        'charger' => '\\bchargers?\\b|\\bcharg(?:ing|e)\\s+(?:cable|lead)\\b|\\busb(?:[\\s-]?[abc])?\\s+(?:cable|lead)s?\\b',
        'controller' => '\\bcontrollers?\\b|\\bgame\\s*pads?\\b|\\bjoypads?\\b|\\bdual\\s*(?:sense|shock)\\b|\\bpads?\\b',
        'dock' => '\\bdock\\b',
        'storage' => '\\bhard\\s+drive\\b|\\bhdd\\b|\\bssd\\b|\\bexpansion\\s+card\\b|\\bmemory\\s+card\\b'
            . '|\\b(?:micro\\s*)?sd\\s+card\\b',
        'screen-protector' => '\\bscreen\\s+protectors?\\b|\\btempere?d?\\s+glass\\b',
        'case' => '\\bcases?\\b|\\bbags?\\b|\\bpouch\\b|\\bsleeve\\b',
        'cover' => '\\b(?:dust\\s+)?covers?\\b',
        'stand' => '\\bstand\\b|\\bwall\\s+(?:mount|bracket)\\b',
        'cooling' => '\\bcool(?:ing|er)\\b|\\bfans?\\b',
        'battery' => '\\bbatter(?:y|ies)\\b|\\bpower\\s+banks?\\b|\\b\\d+\\s?mah\\b',
        'shell' => '\\bface\\s?plates?\\b|\\bshells?\\b|\\bhousing\\b|\\bcover\\s+plate\\b',
        'camera' => '\\bcameras?\\b|\\bkinect\\b|\\bweb\\s?cams?\\b',
        'remote' => '\\bremote\\b',
        'keyboard' => '\\bkeyboard\\b',
        'repair-part' => '\\bthumb\\s?sticks?\\b|\\bhdmi\\s+port\\b|\\bribbon\\b|\\bmotherboard\\b|\\breplacement\\b',
    ];

    /** Kinds Sony, Microsoft and Nintendo make and others copy cheaply; the two are priced apart. */
    private const BRANDED_TYPES = ['controller', 'joy-con', 'joy-con-pair', 'pro-controller', 'power', 'charger', 'dock'];

    private const THIRD_PARTY = '\\b(?:power\\s*a|pdp|hori|8\\s?bitdo|gamesir|nacon|razer|turtle\\s+beach|afterglow|'
        . 'venom|snakebyte|orzly|nexigo|gamrombo|nyko|thrustmaster|steelseries|astro|hyperx|logitech|corsair|sades|'
        . 'kontrol\\s?freek|gameware|skull\\s*&\\s*co|playvital|dbrand|scuf|ghost|subsonic|bigben|compatible|generic|'
        . 'unbranded|aftermarket|non[\\s-]?official|replica|clone)\\b';

    private const OFFICIAL = '\\b(?:official|genuine|oem|original|sony|microsoft|nintendo|dual\\s*sense|dual\\s*shock|'
        . 'xbox\\s+wireless\\s+controller)\\b';

    /** Not an accessory: games, codes, packaging, manuals. These stay plain "unknown". */
    private const NOT_ACCESSORY = '\\bgames?\\b(?!\\s+(?:controller|pad|card\\s+(?:holder|case)|grip))|\\b(?:software|code|download|dlc|box\\s+only|empty\\s+box|packaging|'
        . 'inserts?\\s+only|manual|steelbook|cartridge|amiibo)\\b';

    /** Several items in one listing cannot be valued as one accessory. */
    private const SEVERAL = '\\b\\d+\\s*x\\b|\\bx\\s*\\d+\\b|\\b(?:[2-9]|\\d{2,})\\s*-?\\s*pack\\b|\\bpairs?\\b|\\bjob\\s*lot\\b'
        . '|\\bjoblot\\b|\\bbundle\\b|\\blot\\s+of\\b|\\b(?:[2-9]|\\d{2,})\\s+(?:\\w+\\s+){0,3}controllers\\b';

    /** Kinds no game title is named after, safe to key when the rest of the title reads like a game. */
    private const UNMISTAKABLE = ['controller', 'controller-elite', 'controller-edge', 'pro-controller', 'joy-con',
        'joy-con-pair', 'charging-station', 'disc-drive', 'power', 'hdmi-cable', 'charger', 'storage', 'headset', 'vr'];

    /**
     * Between "console" and the accessory, a size, a count or a list makes the console the item:
     * "PS4 Console (500gb) 1 Controller", "Series X Console, Controller". "Console Wrap" stays a wrap.
     */
    private const CONSOLE_THEN_EXTRAS = '\\bconsoles?\\b.*(?:,|&|\\band\\b|\\(|\\d+\\s?(?:gb|tb)\\b|\\b\\d+\\s*$)';

    /** @param bool $unmistakable only key kinds no game is named after ("The Centennial Case" is a game) */
    public static function key(string $title, bool $unmistakable = false): string
    {
        $text = mb_strtolower(trim($title));
        if ($text === '' || self::search(self::NOT_ACCESSORY, $text)) {
            return self::UNKNOWN_KEY;
        }
        // Joy-Cons are usually sold as a left and right pair, which is one product.
        $joyConPair = self::search('\\bjoy-?\\s?cons?\\b', $text)
            && self::search('\\bpairs?\\b|\\b(?:l|left)\\s*(?:&|and|\\+|/)\\s*(?:r|right)\\b', $text);
        $found = self::type($text);
        if ($found === null) {
            // Nothing says what it is, so nothing says it is an accessory either (a game with its name only).
            return self::UNKNOWN_KEY;
        }
        [$type, $at, $inItem] = $found;
        if ($type === 'joy-con' && $joyConPair) {
            $type = 'joy-con-pair';
        }
        if ($unmistakable && !in_array($type, self::UNMISTAKABLE, true)) {
            return self::UNKNOWN_KEY;
        }
        $item = self::itemPart($text);
        // "Galaxy Switch Lite with Protective Case": the item named first is the console, the case comes with it.
        if (!$inItem && self::platform($item, $type) !== null) {
            return self::UNKNOWN_KEY;
        }
        if ($inItem) {
            $before = mb_substr($text, 0, $at);
            // "PS5 825GB Disc Console FAULTY HDMI PORT" is a faulty console, not a port.
            if (self::search(self::CONSOLE_THEN_EXTRAS, $before)
                || ($type === 'repair-part' && self::search('\\bconsoles?\\b', $before))
            ) {
                return self::UNKNOWN_KEY;
            }
        }
        if (!$joyConPair && self::search(self::SEVERAL, $text)) {
            return self::UNKNOWN;
        }
        $platform = self::platform($text, $type);
        if ($platform === null) {
            return self::UNKNOWN;
        }
        if (in_array($type, self::BRANDED_TYPES, true)) {
            $type .= self::isThirdParty($text) ? '-third-party' : '-official';
        }
        return self::PREFIX . $platform . '-' . $type;
    }

    public static function isAccessoryKey(string $key): bool
    {
        return str_starts_with($key, self::PREFIX) || $key === self::UNKNOWN;
    }

    /**
     * "Dust Cover for Xbox Series X Console", "Camera with Stand": what comes before "for" or "with" says
     * what the item is.
     */
    private static function itemPart(string $text): string
    {
        return preg_split('~\\b(?:for|designed\\s+for|compatible\\s+with|fits|with|incl\\w*)\\b|\\bw/|\\+~u', $text, 2)[0];
    }

    /** @return array{string, int, bool}|null the kind, where it was found, and whether in the item part */
    private static function type(string $text): ?array
    {
        $item = self::itemPart($text);
        foreach ([$item, $text] as $index => $candidate) {
            foreach (self::TYPES as $type => $pattern) {
                $match = self::search($pattern, $candidate);
                if ($match) {
                    return [$type, $match->start(), $index === 0];
                }
            }
        }
        return null;
    }

    private static function platform(string $text, ?string $type): ?string
    {
        // Elite controllers work on Xbox One and Series alike.
        if ($type === 'controller-elite') {
            return self::search('\\bxbox\\b', $text) ? 'xbox' : null;
        }
        $first = null;
        $at = PHP_INT_MAX;
        foreach (self::PLATFORMS as $platform => $pattern) {
            $match = self::search($pattern, $text);
            if ($match && $match->start() < $at) {
                $first = $platform;
                $at = $match->start();
            }
        }
        return $first;
    }

    /**
     * A third-party brand or "compatible" says so outright. Otherwise "Wireless Controller for PS5" names
     * only the console it fits and is not official, while "Xbox One Controller Grey" is the real thing.
     * "Wireless Joy-Con for Nintendo Switch" names the maker of the console, not of the Joy-Con.
     */
    private static function isThirdParty(string $text): bool
    {
        if (self::search(self::THIRD_PARTY, $text)) {
            return true;
        }
        if (self::search(self::OFFICIAL, self::itemPart($text))) {
            return false;
        }
        return (bool)self::search('\\b(?:for|compatible\\s+with|fits)\\b', $text);
    }
}
