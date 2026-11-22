<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey\Rules;

final class Headphones extends Rule
{
    /**
     * Basic string cleaner: None -> "", strip whitespace.
     */
    private static function clean($s): string
    {
        if ($s === null) {
            return '';
        }
        if (is_array($s)) {
            return implode(' ', array_map(static fn($v) => mb_strtolower(self::stringify($v)), self::iterate($s)));
        }
        return trim(self::stringify($s));
    }

    /**
     * Remove anything inside parentheses, including parentheses.
     */
    private static function stripParentheses(string $s): string
    {
        $out = [];
        $depth = 0;
        foreach (mb_str_split($s) as $ch) {
            if ($ch === '(') {
                $depth += 1;
                continue;
            }
            if ($ch === ')') {
                $depth = max(($depth - 1), 0);
                continue;
            }
            if ($depth === 0) {
                $out[] = $ch;
            }
        }
        return implode('', $out);
    }

    /**
     * Normalise Brand into a compact token (lowercase alnum only).
     */
    private static function normaliseBrand($raw): string
    {
        $s = self::clean($raw);
        if (!$s || (in_array(mb_strtolower($s), ['branded', 'unbranded', 'does not apply'], true))) {
            return '';
        }
        $low = mb_strtolower($s);
        if (str_contains($low, 'beats')) {
            return 'beats';
        }
        if (str_contains($low, 'soundcore')) {
            return 'soundcore';
        }
        if (str_contains($low, 'skullcandy')) {
            return 'skullcandy';
        }
        $out = [];
        foreach (mb_str_split($low) as $ch) {
            if (self::isAlphanumeric($ch)) {
                $out[] = $ch;
            }
        }
        return implode('', $out);
    }

    private static function tokeniseModel(string $s): array
    {
        $s = self::stripParentheses($s);
        $s = str_replace('-', ' ', str_replace('\\', ' ', str_replace('/', ' ', $s)));
        $s = implode(' ', self::words($s));
        $toks = [];
        foreach (self::words($s) as $t) {
            $alnum = self::alphanumeric(mb_strtolower($t));
            if ($alnum) {
                $toks[] = $alnum;
            }
        }
        return $toks;
    }

    /**
     * Collapse model tokens into a short, bucketable 'family' token.
     */
    private static function compressModelTokens(array $tokens): string
    {
        if (!$tokens) {
            return '';
        }
        $stopWords = [
            'headphones',
            'headphone',
            'earphones',
            'earphone',
            'earbuds',
            'earbud',
            'inear',
            'over',
            'on',
            'wireless',
            'bluetooth',
            'true',
            'tws',
            'anc',
            'noise',
            'cancelling',
            'canceling',
            'stereo',
            'audio',
            'sound',
            'calls',
            'genuine',
            'original',
            'boxed',
            'edition',
            'limited',
            'special',
            'gen',
            'black',
            'white',
            'silver',
            'grey',
            'gray',
            'blue',
            'red',
            'platinum',
            'na',
            'doesnotapply',
            'lookintitle',
            'other',
            'bud',
            'buds',
        ];
        $joinedPrefixes = [
            'wh',
            'wf',
            'wi',
            'mdr',
            'hd',
            'dt',
            'ie',
            'se',
            'ath',
            'qc',
            'rs',
            'quietcomfort',
            'momentum',
            'crusher',
            'major',
            'tour',
            'tune',
            'live',
            'liberty',
            'space',
            'powerbeats',
            'studio',
            'solo',
            'beoplay',
            'anker',
        ];
        $out = [];
        $haveDigit = false;
        foreach ($tokens as $tok) {
            if (in_array($tok, $stopWords, true)) {
                continue;
            }
            if (!$out) {
                $out[] = $tok;
                $haveDigit = (bool)(self::search('\\d', $tok));
                continue;
            }
            if ((self::length($out) === 1) && (in_array($out[0], $joinedPrefixes, true))) {
                $out[] = $tok;
                $haveDigit = ($haveDigit ?: (bool)(self::search('\\d', $tok)));
                if ($haveDigit || (in_array($tok, ['ultra', 'max', 'v', 'iv', 'ii', 'iii', 'pro'], true))) {
                    break;
                }
                continue;
            }
            if ((self::length($out) === 1) && (self::search('\\d', $tok) || (in_array($tok, ['ultra', 'max', 'pro'], true)))) {
                $out[] = $tok;
                break;
            }
            break;
        }
        return ($out ? implode('', $out) : '');
    }

    private static function normaliseModel($rawModel, $rawBrand): string
    {
        $s = self::clean($rawModel);
        if (!$s) {
            return '';
        }
        $low = mb_strtolower($s);
        if (in_array($low, ['n/a', 'na', 'does not apply', 'doesnotapply', 'look in title', 'other'], true)) {
            return '';
        }
        $tokens = self::tokeniseModel($s);
        if (!$tokens) {
            return '';
        }
        $brandRaw = self::clean($rawBrand);
        $brandTokens = self::tokeniseModel($brandRaw);
        $brandFirst = ($brandTokens ? $brandTokens[0] : '');
        if ($brandFirst && ($tokens[0] === $brandFirst)) {
            $tokens = array_slice($tokens, 1);
        }
        return self::compressModelTokens($tokens);
    }

    /**
     * Build a canonical model key for headphone listings (source='ebay-headphones').
     *
     * Output format: {brand}-{family}
     *
     * Examples:
     *     Brand="Bose", Model="Bose QuietComfort Ultra" -> "bose-quietcomfortultra"
     *     Brand="Sony", Model="WH-1000XM4" -> "sony-wh1000xm4"
     *     Brand="Marshall", Model="Major V" -> "marshall-majorv"
     */
    public static function headphonesModelKey(array $attrs, string $title = ''): ?string
    {
        $rawBrand = ($attrs['Brand'] ?? null);
        $brand = self::normaliseBrand($rawBrand);
        $rawModel = (($attrs['Model'] ?? null) ?: ($attrs['MPN'] ?? null) ?: ($attrs['Manufacturer Part Number'] ?? null) ?: ($attrs['Item Code'] ?? null));
        $modelCore = self::normaliseModel($rawModel, $rawBrand);
        if (!$brand || !$modelCore) {
            return self::UNKNOWN_KEY;
        }
        $baseKey = $brand . '-' . $modelCore;
        return $baseKey;
    }
}
