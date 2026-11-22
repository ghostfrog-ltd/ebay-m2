<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey\Rules;

final class Pokemon extends Rule
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
            $s = implode(' ', array_map(static fn($v) => self::stringify($v), $s));
        }
        $text = trim(self::stringify($s));
        $text = str_replace('pokÃ©mon', 'pokemon', str_replace('PokÃ©mon', 'Pokemon', $text));
        $text = str_replace('pokÃmon', 'pokemon', str_replace('PokÃmon', 'Pokemon', $text));
        return self::withoutAccents($text);
    }

    /**
     * Lowercase, keep alphanumerics only.
     */
    private static function alnumToken(string $s): string
    {
        return self::alphanumeric(mb_strtolower($s));
    }

    /**
     * Identifies professional grading (PSA, BGS, CGC) and numeric scores.
     * Scans Autograph Authentication, Professional Grader, and Title.
     */
    private static function extractProfessionalGrade(array $attrs, string $title): string
    {
        $auth = self::clean(($attrs['Autograph Authentication'] ?? null));
        $grader = self::clean(($attrs['Professional Grader'] ?? null));
        $searchText = $auth . ' ' . $grader . ' ' . mb_strtolower($title);
        $company = '';
        if (self::search('\\bpsa\\b', $searchText) || (str_contains($searchText, 'professional sports authenticator'))) {
            $company = 'psa';
        } else {
            if ((str_contains($searchText, 'beckett')) || self::search('\\bbgs\\b', $searchText) || self::search('\\bbas\\b', $searchText)) {
                $company = 'bgs';
            } else {
                if (self::search('\\bcgc\\b', $searchText)) {
                    $company = 'cgc';
                }
            }
        }
        if (!$company) {
            return '';
        }
        $gradeMatch = self::search($company . '\\s?(\\d{1,2}(?:\\.\\d)?)', $searchText);
        if ($gradeMatch) {
            return $company . str_replace('.', '', $gradeMatch->group(1));
        }
        return $company;
    }

    /**
     * Extracts card finish/rarity (e.g., 'holo', 'rev', 'vmax').
     */
    private static function extractFinish(array $attrs, string $title): string
    {
        $candidates = [
            self::clean(($attrs['Speciality'] ?? null)),
            self::clean(($attrs['Spezialkarte'] ?? null)),
            self::clean(($attrs['Features'] ?? null)),
            mb_strtolower($title),
        ];
        $blob = implode(' ', $candidates);
        if ((str_contains($blob, 'reverse holo')) || (str_contains($blob, 'rev holo'))) {
            return 'rev';
        }
        if (str_contains($blob, 'holo')) {
            return 'holo';
        }
        if (str_contains($blob, 'vmax')) {
            return 'vmax';
        }
        if (str_contains($blob, 'vstar')) {
            return 'vstar';
        }
        if (str_contains($blob, 'gx')) {
            return 'gx';
        }
        if ((str_contains($blob, ' ex')) || (str_contains($blob, 'ex '))) {
            return 'ex';
        }
        if (str_contains($blob, 'promo')) {
            return 'promo';
        }
        return '';
    }

    /**
     * Detects franchise, defaulting to 'pokemon' if signals match.
     */
    private static function normaliseFranchise(array $attrs): string
    {
        $candidates = [
            self::clean(($attrs['Franchise'] ?? null)),
            self::clean(($attrs['Game'] ?? null)),
            self::clean(($attrs['Manufacturer'] ?? null)),
        ];
        $joined = mb_strtolower(implode(' | ', $candidates));
        if (self::containsAny($joined, ['pokémon', 'pokemon', 'the pokemon company'])) {
            return 'pokemon';
        }
        foreach ($candidates as $c) {
            if ($c) {
                return (self::alnumToken($c) ?: 'unknown');
            }
        }
        return 'unknown';
    }

    /**
     * Normalises Set names (e.g., 'Evolving Skies' -> 'evolvingskies').
     */
    private static function normaliseSet($rawSet): string
    {
        $s = self::clean($rawSet);
        if (!$s || (in_array($s, ['random', 'mix', 'mixed'], true))) {
            return '';
        }
        return self::alnumToken($s);
    }

    /**
     * Extracts stable card number tokens like '151-165'.
     */
    private static function extractCardNumber($raw): string
    {
        $s = self::clean($raw);
        if (!$s || (str_contains($s, 'cards'))) {
            return '';
        }
        $m = self::search('(\\d{1,4})\\s*/\\s*(\\d{1,4})', $s);
        if ($m) {
            return str_pad($m->group(1), 3, '0', STR_PAD_LEFT) . '-' . str_pad($m->group(2), 3, '0', STR_PAD_LEFT);
        }
        $compact = self::alphanumeric(mb_strtoupper($s));
        if ((self::length($compact) >= 3) && self::any(mb_str_split($compact), static fn($ch) => self::isDigit($ch))) {
            return $compact;
        }
        return '';
    }

    /**
     * Compresses card names (e.g., 'Charizard ex' -> 'charizardex').
     */
    private static function compressCardName($raw): string
    {
        $s = self::clean($raw);
        if (!$s || (in_array($s, ['pokemon', 'energy', 'foil', 'hit'], true))) {
            return '';
        }
        $low = trim(self::replacePattern('[^a-z0-9]+', ' ', mb_strtolower($s)));
        $tokens = array_values(array_filter(self::words($low), static fn($t) => ($t && (!in_array($t, ['the', 'and', 'of', 'to'], true)))));
        if (!$tokens) {
            return '';
        }
        $res = array_slice($tokens, 0, 2);
        $suffixes = ['ex', 'v', 'vmax', 'vstar', 'gx', 'promo'];
        foreach (array_slice($tokens, 2) as $t) {
            if (in_array($t, $suffixes, true)) {
                $res[] = $t;
                break;
            }
        }
        return implode('', $res);
    }

    /**
     * Builds canonical card key: {franchise}-{set}-{cardcore}-{finish}{_lang?}{-prograde?}
     *
     * Condition is deliberately stored separately in auction_listings.condition_grade.
     */
    public static function pokemonModelKey(array $attrs, string $title = ''): ?string
    {
        $franchise = self::normaliseFranchise($attrs);
        $normaliseBrand = self::normaliseFranchise($attrs);
        $setToken = self::normaliseSet(($attrs['Set'] ?? null));
        $cardNum = self::extractCardNumber(($attrs['Card Number'] ?? null));
        $cardName = self::compressCardName(($attrs['Card Name'] ?? null));
        $cardCore = ($cardNum ?: $cardName);
        if (!$cardCore) {
            $cardCore = (self::extractCardNumber($title) ?: self::compressCardName($title));
        }
        if (!$cardCore) {
            return self::UNKNOWN_KEY;
        }
        $finish = self::extractFinish($attrs, $title);
        $proGrade = self::extractProfessionalGrade($attrs, $title);
        $lang = (($attrs['Language'] ?? null) ? mb_substr(self::clean(($attrs['Language'] ?? null)), 0, 2) : '');
        $baseParts = [$franchise];
        if ($setToken) {
            $baseParts[] = $setToken;
        }
        $baseParts[] = $cardCore;
        if ($finish) {
            $baseParts[] = $finish;
        }
        $baseKey = implode('-', $baseParts);
        if ($lang) {
            $baseKey .= '_' . $lang;
        }
        if ($proGrade) {
            $baseKey .= '-' . $proGrade;
        }
        return $baseKey;
    }
}
