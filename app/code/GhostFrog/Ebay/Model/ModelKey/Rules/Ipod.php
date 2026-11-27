<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey\Rules;

use GhostFrog\Ebay\Model\ModelKey\Evidence;

/**
 * iPods, keyed apple-ipod-{line}-{generation}-{storage}, for example apple-ipod-nano-7th-gen-16gb.
 *
 * Generation drives iPod prices far more than capacity (a 2nd-gen and a 7th-gen 16GB nano sell years
 * apart), so a listing without a readable generation stays unknown. The "iPod video", photo and the
 * original click-wheel iPods all count as the classic line, which is how sellers list them. Minis are the
 * one exception: both generations sell for the same money, so mini keys carry no generation.
 *
 * Capacities outside what Apple shipped for that generation, or flash/iFlash/SSD wording, mark an iPod
 * as modded ("-mod-"), since a 512GB flash-modded classic is a different product from a stock 80GB one.
 */
final class Ipod
{
    private const CONFLICT = -1.0;

    /** Capacities Apple sold, in GB, by line and generation. */
    private const FACTORY = [
        'classic' => [1 => [5, 10], 2 => [10, 20], 3 => [10, 15, 20, 30, 40], 4 => [20, 30, 40, 60],
            5 => [30, 60, 80], 6 => [80, 120, 160], 7 => [160]],
        'mini' => [1 => [4], 2 => [4, 6]],
        'nano' => [1 => [1, 2, 4], 2 => [2, 4, 8], 3 => [4, 8], 4 => [4, 8, 16], 5 => [8, 16], 6 => [8, 16],
            7 => [16]],
        'shuffle' => [1 => [0.5, 1], 2 => [1, 2], 3 => [2, 4], 4 => [2]],
        'touch' => [1 => [8, 16, 32], 2 => [8, 16, 32], 3 => [32, 64], 4 => [8, 16, 32, 64], 5 => [16, 32, 64],
            6 => [16, 32, 64, 128], 7 => [32, 128, 256]],
    ];

    /** Apple model numbers printed on the back, as [line, generation]; 0 when the number spans generations. */
    private const MODEL_NUMBERS = [
        'a1040' => ['classic', 3], 'a1059' => ['classic', 4], 'a1099' => ['classic', 4], 'a1136' => ['classic', 5],
        'a1238' => ['classic', 0], 'a1051' => ['mini', 0],
        'a1137' => ['nano', 1], 'a1199' => ['nano', 2], 'a1236' => ['nano', 3], 'a1285' => ['nano', 4],
        'a1320' => ['nano', 5], 'a1366' => ['nano', 6], 'a1446' => ['nano', 7],
        'a1112' => ['shuffle', 1], 'a1204' => ['shuffle', 2], 'a1271' => ['shuffle', 3], 'a1373' => ['shuffle', 4],
        'a1213' => ['touch', 1], 'a1288' => ['touch', 2], 'a1318' => ['touch', 3], 'a1367' => ['touch', 4],
        'a1421' => ['touch', 5], 'a1509' => ['touch', 5], 'a1574' => ['touch', 6], 'a2178' => ['touch', 7],
    ];

    /** Batteries, iFlash boards, docks and other parts are sold "for" an iPod rather than as one. */
    private const ACCESSORY = '~^(?:for|fits)\b|\b(?:for|fits|compatible with|suits)\s+(?:the\s+)?(?:apple\s+)?ipods?\b|'
        . '\b(?:battery replacement|replacement battery|click ?wheel only|hard drive only|logic ?board only|'
        . '(?:adapter|dock|cable|lanyard|pouch|case|charger|headphones|earphones) only)\b~';

    private const LOT = '~\b(?:job ?lot|joblot|bundle of|lot of|\d+\s*x\s*ipods?|ipods\b)~';

    private const MOD = '~\b(?:i-?flash|flash (?:mod|upgrade|storage)|sd (?:card )?mod|ssd|compact ?flash|cf card|'
        . 'mod(?:ded)?|upgraded? (?:to )?\d+\s*gb|taptic|rockbox)\b~';

    public static function key(array $attrs, string $title): string
    {
        $title = Evidence::texts([], $title)[0] ?? '';
        if ($title === '' || preg_match(self::ACCESSORY, $title) || preg_match(self::LOT, $title)) {
            return 'unknown';
        }
        $numbers = self::modelNumbers($attrs, $title);
        $line = self::line($attrs, $title, $numbers);
        if ($line === '') {
            return 'unknown';
        }
        $generation = self::generation($attrs, $title, $line, $numbers);
        $storage = self::storage($attrs, $title);
        if ($storage === self::CONFLICT) {
            return 'unknown';
        }
        $stock = array_merge(...array_values(self::FACTORY[$line]));
        // Flash wording, or a size Apple never sold in this line (a 128GB classic), means a modded iPod.
        $modded = preg_match(self::MOD, $title) === 1 || ($storage !== null && !in_array($storage, $stock, false));
        if (!$modded && $storage !== null
            && ($generation === 0 || !in_array($storage, self::FACTORY[$line][$generation], false))
        ) {
            // A capacity only one generation shipped with pins the model down, and wins over a seller's
            // generation that never came in that size (the 120GB "7th gen" classic is a 6th).
            $generation = self::generationFromCapacity($line, $storage) ?: $generation;
        }
        if ($generation === 0 && !$modded && $line !== 'mini') {
            return 'unknown';
        }
        $factory = $generation > 0 ? self::FACTORY[$line][$generation] : $stock;
        if ($storage === null && count(array_unique($factory)) === 1) {
            $storage = (float)$factory[0];
        }
        if ($storage === null) {
            return 'unknown';
        }
        $modded = $modded || $storage > max($factory);
        if (!$modded && !in_array($storage, $factory, false)) {
            // A stock size from another generation and no mod wording: the listing contradicts itself.
            return 'unknown';
        }
        return implode('-', array_filter([
            'apple-ipod',
            $line,
            // Mods are priced on capacity and battery; minis sell the same across both generations.
            $generation > 0 && !$modded && $line !== 'mini' ? Evidence::ordinal($generation) : '',
            $modded ? 'mod' : '',
            self::capacity($storage),
        ]));
    }

    /** @return array<int, array{string, int}> */
    private static function modelNumbers(array $attrs, string $title): array
    {
        $found = [];
        foreach (Evidence::identifiers(Evidence::texts($attrs, $title, 'Model', 'MPN', 'Model Number')) as $number) {
            if (isset(self::MODEL_NUMBERS[$number])) {
                $found[$number] = self::MODEL_NUMBERS[$number];
            }
        }
        return array_values($found);
    }

    /** The title decides; the Model item specific, then the model number, fill in when it names no line. */
    private static function line(array $attrs, string $title, array $numbers): string
    {
        foreach ([[$title], Evidence::texts($attrs, '', 'Model'), Evidence::texts($attrs, '', 'Product Line')] as $texts) {
            // "iPod nano" beats a stray word, as in "iPod mini ... Retro Classic" or "nano with mini USB".
            foreach (['~\bipod\s+(touch|nano|classic|shuffle|mini|video|photo|colou?r|u2)\b~',
                '~\b(touch|nano|classic|shuffle)\b~'] as $pattern
            ) {
                $lines = Evidence::unique(array_map(static fn (string $name): string => match ($name) {
                    'video', 'photo', 'color', 'colour', 'u2' => 'classic',
                    default => $name,
                }, Evidence::matches($pattern, array_filter($texts))));
                if (count($lines) === 1) {
                    return $lines[0];
                }
                if (count($lines) > 1) {
                    return '';
                }
            }
        }
        $lines = Evidence::unique(array_column($numbers, 0));
        if (count($lines) === 1) {
            return $lines[0];
        }
        // "Apple iPod 3rd Generation 20GB" is an original click-wheel iPod, which sells with the classics.
        return preg_match('~\bipod\b~', $title) && !preg_match('~\bipod\s+(?:dock|speaker|case|hi-?fi)\b~', $title)
            ? 'classic' : '';
    }

    private static function generation(array $attrs, string $title, string $line, array $numbers): int
    {
        $max = max(array_keys(self::FACTORY[$line]));
        // "1st/2nd gen" or "3rd & 4th gen" lists more than one model.
        if (!preg_match('~\b\d(?:st|nd|rd|th)?\s*(?:/|&|and|or|-)\s*\d(?:st|nd|rd|th)\b~', $title)) {
            $generations = Evidence::generations([$title]);
            if (count($generations) === 1 && $generations[0] >= 1 && $generations[0] <= $max) {
                return $generations[0];
            }
            if (count($generations) > 1) {
                return 0;
            }
        }
        foreach ($numbers as [$numberLine, $generation]) {
            if ($numberLine === $line && $generation > 0) {
                return $generation;
            }
        }
        $values = array_filter(Evidence::texts($attrs, '', 'Generation'));
        if (count($values) === 1) {
            preg_match_all('~\b(\d)(?:\.\d)?(?:st|nd|rd|th)?\b~', reset($values), $matches);
            $generations = Evidence::unique(array_map('intval', $matches[1]));
            if (count($generations) === 1 && $generations[0] >= 1 && $generations[0] <= $max) {
                return $generations[0];
            }
        }
        return 0;
    }

    private static function generationFromCapacity(string $line, ?float $storage): int
    {
        if ($storage === null) {
            return 0;
        }
        $generations = array_keys(array_filter(self::FACTORY[$line],
            static fn (array $sizes): bool => in_array($storage, $sizes, false)));
        return count($generations) === 1 ? $generations[0] : 0;
    }

    /**
     * One capacity from the title, else from the item specifics; iPods ship in sizes from 512MB.
     * Several capacities in one place (a bundle, or "4GB 8GB") return CONFLICT.
     */
    private static function storage(array $attrs, string $title): ?float
    {
        foreach ([[$title], Evidence::texts($attrs, '', 'Storage Capacity', 'Capacity', 'Hard Drive Capacity')] as $texts) {
            $sizes = [];
            foreach ($texts as $text) {
                preg_match_all('~(?<![\d.])(\d{1,4})\s*(gb|mb|tb)\b~', $text, $matches, PREG_SET_ORDER);
                foreach ($matches as [, $amount, $unit]) {
                    $sizes[] = match ($unit) {
                        'mb' => (int)$amount / 1024,
                        'tb' => (int)$amount * 1024,
                        default => (int)$amount,
                    };
                }
            }
            $sizes = array_values(array_unique($sizes, SORT_REGULAR));
            if (count($sizes) === 1) {
                return (float)$sizes[0];
            }
            if (count($sizes) > 1) {
                return self::CONFLICT;
            }
        }
        return null;
    }

    private static function capacity(float $gb): string
    {
        return $gb < 1 ? (int)round($gb * 1024) . 'mb' : (int)$gb . 'gb';
    }
}
