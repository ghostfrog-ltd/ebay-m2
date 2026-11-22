<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey\Rules;

final class Watches extends Rule
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
     * Detects Box and Papers. In watches, 'Full Set' is the ultimate price anchor.
     * Scans 'With Original Box/Packaging' and 'With Papers'.
     */
    private static function extractProvenance(array $attrs, string $title): string
    {
        $box = mb_strtolower(self::clean(($attrs['With Original Box/Packaging'] ?? null)));
        $papers = mb_strtolower(self::clean(($attrs['With Papers'] ?? null)));
        $titleLow = mb_strtolower($title);
        $hasBox = ((str_contains($box, 'yes')) || (str_contains($titleLow, 'with box')) || (str_contains($titleLow, 'boxed')));
        $hasPapers = ((str_contains($papers, 'yes')) || (str_contains($titleLow, 'with papers')) || (str_contains($titleLow, 'paperwork')) || (str_contains($titleLow, 'certificate')) || (str_contains($titleLow, 'full set')));
        if ($hasBox && $hasPapers) {
            return 'fullset';
        }
        if ($hasBox) {
            return 'box';
        }
        if ($hasPapers) {
            return 'papers';
        }
        return 'watchonly';
    }

    /**
     * Differentiates between Quartz and Mechanical (Automatic/Manual).
     * Crucial for brands like Seiko or Omega where movement dictates value tiers.
     */
    private static function extractMovement(array $attrs, string $title): string
    {
        $m = mb_strtolower(self::clean(($attrs['Movement'] ?? null)));
        $searchText = $m . ' ' . mb_strtolower($title);
        if (str_contains($searchText, 'kinetic')) {
            return 'kinetic';
        }
        if (str_contains($searchText, 'automatic')) {
            return 'auto';
        }
        if ((str_contains($searchText, 'manual')) || (str_contains($searchText, 'hand-winding'))) {
            return 'manual';
        }
        if ((str_contains($searchText, 'quartz')) || (str_contains($searchText, 'solar'))) {
            return 'quartz';
        }
        return '';
    }

    /**
     * Normalises Brand/Make to compact alphanumeric.
     */
    private static function normaliseBrand($raw): string
    {
        $s = mb_strtolower(self::clean($raw));
        if (!$s || (in_array($s, ['other', 'unbranded', 'does not apply'], true))) {
            return '';
        }
        if (str_contains($s, 'seiko')) {
            return 'seiko';
        }
        if (str_contains($s, 'omega')) {
            return 'omega';
        }
        if (str_contains($s, 'rolex')) {
            return 'rolex';
        }
        if (str_contains($s, 'casio')) {
            return 'casio';
        }
        if ((str_contains($s, 'g-shock')) || (str_contains($s, 'gshock'))) {
            return 'gshock';
        }
        return self::alphanumeric($s);
    }

    /**
     * Infer a known watch brand from title-only Browse rows.
     */
    private static function brandFromTitle(string $title): string
    {
        $titleLow = mb_strtolower($title);
        foreach (['seiko', 'omega', 'rolex', 'casio', 'g-shock', 'gshock'] as $brand) {
            if (self::search('\\b' . preg_quote($brand) . '\\b', $titleLow)) {
                return ((in_array($brand, ['g-shock', 'gshock'], true)) ? 'gshock' : str_replace('-', '', $brand));
            }
        }
        return '';
    }

    /**
     * Extracts the high-confidence Reference Number.
     */
    private static function extractReference(array $attrs): string
    {
        $ref = (($attrs['Reference Number'] ?? null) ?: ($attrs['ReferenceNumber'] ?? null));
        if (!$ref) {
            return '';
        }
        if (is_array($ref)) {
            $candidates = array_map(static fn($r) => self::alphanumeric(mb_strtolower(self::stringify($r))), self::iterate($ref));
            return ($candidates ? self::longest($candidates) : '');
        }
        $cleanRef = self::alphanumeric(mb_strtolower(self::stringify($ref)));
        if (in_array($cleanRef, ['none', 'na', 'doesnotapply'], true)) {
            return '';
        }
        return $cleanRef;
    }

    /**
     * Extract common watch reference codes from title-only rows.
     */
    private static function extractReferenceFromTitle(string $title): string
    {
        $titleLow = mb_strtolower($title);
        $patterns = ['\\b([a-z]{2,4}\\d{3,5}[a-z0-9]*)\\b', '\\b([0-9][a-z][0-9]{2}[-\\s]?[0-9a-z]{3,5})\\b'];
        foreach ($patterns as $pattern) {
            $match = self::search($pattern, $titleLow);
            if ($match) {
                return self::alphanumeric($match->group(1));
            }
        }
        return '';
    }

    /**
     * Fallback: Extracts model family if reference is missing.
     */
    private static function normaliseModelCore(array $attrs, string $brand): string
    {
        $m = self::clean((($attrs['Model'] ?? null) ?: ($attrs['Watch Model'] ?? null)));
        if (!$m || (in_array($m, ['watch', 'mens', 'womens'], true))) {
            return '';
        }
        $tokens = self::words(str_replace('-', ' ', $m));
        if ($tokens && ($tokens[0] === $brand)) {
            $tokens = array_slice($tokens, 1);
        }
        $res = implode('', array_map(static fn($t) => self::alphanumeric($t), array_slice($tokens, 0, 2)));
        return $res;
    }

    /**
     * Extract a stable model family from title-only watch rows.
     */
    private static function modelFromTitle(string $title, string $brand): string
    {
        $titleLow = mb_strtolower($title);
        $familyTerms = ['flightmaster', 'seiko 5', 'speedmaster', 'seamaster', 'submariner', 'g-shock'];
        foreach ($familyTerms as $term) {
            if (str_contains($titleLow, $term)) {
                return self::alphanumeric($term);
            }
        }
        if ($brand) {
            $afterBrand = self::search('\\b' . preg_quote($brand) . '\\b\\s+([a-z0-9-]{3,18})', $titleLow);
            if ($afterBrand) {
                return self::alphanumeric($afterBrand->group(1));
            }
        }
        return '';
    }

    /**
     * Builds canonical watch key: {brand}-{ref_or_model}-{movement?}-{provenance}
     *
     * Example: Brand="Seiko", Ref="SKX007", Movement="Automatic", Provenance="Full Set"
     *          -> "seiko-skx007-auto-fullset".
     */
    public static function watchModelKey(array $attrs, string $title = ''): ?string
    {
        $brand = (self::normaliseBrand(($attrs['Brand'] ?? null)) ?: self::brandFromTitle($title));
        $ref = (self::extractReference($attrs) ?: self::extractReferenceFromTitle($title));
        $model = (self::normaliseModelCore($attrs, $brand) ?: self::modelFromTitle($title, $brand));
        $identity = ($ref ?: $model);
        if (!$brand || !$identity) {
            return self::UNKNOWN_KEY;
        }
        $movement = self::extractMovement($attrs, $title);
        $provenance = self::extractProvenance($attrs, $title);
        $parts = [$brand, $identity];
        if ($movement) {
            $parts[] = $movement;
        }
        $parts[] = $provenance;
        $baseKey = implode('-', $parts);
        return $baseKey;
    }
}
