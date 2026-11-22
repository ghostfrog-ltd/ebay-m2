<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey\Rules;

final class Cameras extends Rule
{
    private static function clean($value): string
    {
        if ($value === null) {
            return '';
        }
        return mb_strtolower(trim(self::stringify($value)));
    }

    private static function attrBlob(array $attrs): string
    {
        $parts = [];
        foreach (array_values($attrs) as $value) {
            if (is_array($value)) {
                array_push($parts, ...array_map(static fn($item) => self::clean($item), self::iterate($value)));
            } else {
                $parts[] = self::clean($value);
            }
        }
        return implode(' ', array_values(array_filter($parts, static fn($part) => $part)));
    }

    private static function text(array $attrs, string $title): string
    {
        return trim(self::clean($title) . ' ' . self::attrBlob($attrs));
    }

    private static function isAccessoryOrNonCamera(string $text): bool
    {
        return self::containsAny($text, [
            'lens mod',
            'max lens',
            'media mod',
            'display mod',
            'light mod',
            'charger only',
            'battery only',
            'case only',
            'mount only',
            'housing only',
            'frame only',
            'floaty',
            'strap',
            'harness',
            'chest mount',
            'head strap',
            'waterproof case',
            'protective case only',
            'screen protector',
            'nd filter',
            'filter set',
            'mic adapter',
            'drone only',
            'gimbal only',
            'karma drone',
            'mavic',
            'phantom',
            'mini 4 pro',
            'mini 3 pro',
        ]);
    }

    private static function storage(string $title, array $attrs): string
    {
        $text = implode(' ', [
            self::clean($title),
            self::clean(($attrs['Flash Memory Installed'] ?? null)),
            self::clean(($attrs['Storage Capacity'] ?? null)),
            self::clean(($attrs['storage_gb'] ?? null)),
        ]);
        if (self::search('\\b128\\s?gb\\b', $text)) {
            return '128gb';
        }
        if (self::search('\\b64\\s?gb\\b', $text)) {
            return '64gb';
        }
        if (self::search('\\b32\\s?gb\\b', $text)) {
            return '32gb';
        }
        return '';
    }

    private static function goproKey(string $titleText, string $text): string
    {
        if ((!str_contains($text, 'gopro')) && (!str_contains($text, 'hero'))) {
            return self::UNKNOWN_KEY;
        }
        if (self::search('\\bgopro\\s+max\\b|\\bmax\\s+360\\b', $text)) {
            return 'gopro-max';
        }
        if (str_contains($text, 'fusion')) {
            return 'gopro-fusion';
        }
        if ((str_contains($text, 'hero 2018')) || self::search('\\bhero\\s+4k\\b', $titleText)) {
            return 'gopro-hero-2018';
        }
        $match = self::search('\\bhero\\s*([0-9]{1,2})(?:\\s*(?:\\+|plus))?(?=\\b|\\s|$)', $text);
        if (!$match) {
            return self::UNKNOWN_KEY;
        }
        $generation = intval($match->group(1));
        if (in_array($generation, [8, 9, 10, 11, 12, 13], true)) {
            if ((str_contains($titleText, 'mini')) && (in_array($generation, [11, 13], true))) {
                return 'gopro-hero' . $generation . '-mini';
            }
            return 'gopro-hero' . $generation;
        }
        if ($generation === 7) {
            if (str_contains($titleText, 'silver')) {
                return 'gopro-hero7-silver';
            }
            if (str_contains($titleText, 'white')) {
                return 'gopro-hero7-white';
            }
            if (str_contains($titleText, 'black')) {
                return 'gopro-hero7-black';
            }
            return self::UNKNOWN_KEY;
        }
        if ($generation === 6) {
            return 'gopro-hero6-black';
        }
        if ($generation === 5) {
            if (str_contains($titleText, 'session')) {
                return 'gopro-hero5-session';
            }
            return 'gopro-hero5-black';
        }
        if ($generation === 4) {
            if (str_contains($titleText, 'session')) {
                return 'gopro-hero4-session';
            }
            if (str_contains($titleText, 'silver')) {
                return 'gopro-hero4-silver';
            }
            if (str_contains($titleText, 'black')) {
                return 'gopro-hero4-black';
            }
            return self::UNKNOWN_KEY;
        }
        if ($generation === 3) {
            if ((str_contains($text, '+')) || (str_contains($text, 'plus'))) {
                return 'gopro-hero3plus';
            }
            return 'gopro-hero3';
        }
        return self::UNKNOWN_KEY;
    }

    private static function djiKey(string $titleText, string $text): string
    {
        if (!self::containsAny($text, ['dji', 'osmo', 'action 2', 'action 3', 'action 4', 'action 5', 'action 6'])) {
            return self::UNKNOWN_KEY;
        }
        if (self::search('\\bosmo\\s+pocket\\s*3\\b|\\bpocket\\s*3\\b', $text)) {
            return 'dji-osmo-pocket-3';
        }
        if (self::search('\\bosmo\\s*360\\b', $text)) {
            return 'dji-osmo-360';
        }
        if (self::search('\\baction\\s*2\\b', $text)) {
            return 'dji-action-2';
        }
        $match = self::search('\\b(?:osmo\\s+)?action\\s*([3-6])\\b', $text);
        if (!$match) {
            return self::UNKNOWN_KEY;
        }
        $generation = $match->group(1);
        if ($generation === '5') {
            return ((str_contains($titleText, 'pro')) ? 'dji-osmo-action-5-pro' : self::UNKNOWN_KEY);
        }
        return 'dji-osmo-action-' . $generation;
    }

    private static function insta360GoKey(string $titleText, array $attrs): string
    {
        $storage = self::storage($titleText, $attrs);
        if (self::search('\\bgo\\s*ultra\\b', $titleText)) {
            return 'insta360-go-ultra';
        }
        if (self::search('\\bgo\\s*3s\\b', $titleText)) {
            return ($storage ? 'insta360-go-3s-' . $storage : self::UNKNOWN_KEY);
        }
        if (self::search('\\bgo\\s*3\\b', $titleText)) {
            return ($storage ? 'insta360-go-3-' . $storage : self::UNKNOWN_KEY);
        }
        if (self::search('\\bgo\\s*2\\b', $titleText)) {
            return 'insta360-go-2';
        }
        return self::UNKNOWN_KEY;
    }

    private static function insta360Key(string $titleText, string $text, array $attrs): string
    {
        if ((!str_contains($text, 'insta360')) && (!str_contains($text, 'insta 360'))) {
            return self::UNKNOWN_KEY;
        }
        if (self::search('\\bone\\s*x2\\b', $titleText)) {
            return 'insta360-one-x2';
        }
        if (self::search('\\bone\\s*x\\b', $titleText)) {
            return 'insta360-one-x';
        }
        if (self::search('\\bone\\s*rs\\b', $titleText)) {
            if (str_contains($titleText, 'twin')) {
                return 'insta360-one-rs-twin';
            }
            return 'insta360-one-rs';
        }
        if (self::search('\\bone\\s*r\\b', $titleText)) {
            if (str_contains($titleText, 'twin')) {
                return 'insta360-one-r-twin';
            }
            return 'insta360-one-r';
        }
        foreach (['5', '4', '3', '2'] as $generation) {
            if (self::search('\\bx\\s*' . $generation . '\\b', $titleText)) {
                return 'insta360-x' . $generation;
            }
        }
        if (self::search('\\bace\\s*pro\\s*2\\b', $titleText)) {
            return 'insta360-ace-pro-2';
        }
        if (self::search('\\bace\\s*pro\\b', $titleText)) {
            return 'insta360-ace-pro';
        }
        if (self::search('\\bace\\b', $titleText)) {
            return 'insta360-ace';
        }
        $goKey = self::insta360GoKey($titleText, $attrs);
        if ($goKey !== self::UNKNOWN_KEY) {
            return $goKey;
        }
        return self::UNKNOWN_KEY;
    }

    private static function secondaryKey(string $titleText): string
    {
        if (str_contains($titleText, 'akaso')) {
            if (self::search('\\bek7000\\s+pro\\b', $titleText)) {
                return 'akaso-ek7000-pro';
            }
            if (str_contains($titleText, 'ek7000')) {
                return 'akaso-ek7000';
            }
            if (str_contains($titleText, 'brave 7 le')) {
                return 'akaso-brave-7-le';
            }
            if (str_contains($titleText, 'brave 7')) {
                return 'akaso-brave-7';
            }
            if (self::search('\\bv50\\b', $titleText)) {
                return 'akaso-v50';
            }
        }
        if (str_contains($titleText, 'drift ghost xl')) {
            return 'drift-ghost-xl';
        }
        if (str_contains($titleText, 'sjcam c300')) {
            return 'sjcam-c300';
        }
        return self::UNKNOWN_KEY;
    }

    public static function cameraDroneModelKey(array $attrs, string $title = ''): ?string
    {
        $safeAttrs = ($attrs ?: []);
        $titleText = self::clean($title);
        $text = self::text($safeAttrs, $title);
        if (self::isAccessoryOrNonCamera($text)) {
            return self::UNKNOWN_KEY;
        }
        foreach ([
            self::goproKey($titleText, $text),
            self::djiKey($titleText, $text),
            self::insta360Key($titleText, $text, $safeAttrs),
            self::secondaryKey($titleText),
        ] as $key) {
            if ($key !== self::UNKNOWN_KEY) {
                return $key;
            }
        }
        return self::UNKNOWN_KEY;
    }
}
