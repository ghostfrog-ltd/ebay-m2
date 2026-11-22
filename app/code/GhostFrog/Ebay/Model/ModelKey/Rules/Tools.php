<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey\Rules;

final class Tools extends Rule
{
    private const TITLE_BRANDS = [
        'mafell',
        'dewalt',
        'makita',
        'milwaukee',
        'bosch',
        'festool',
        'hilti',
        'ryobi',
        'metabo',
        'einhell',
        'hitachi',
        'hikoki',
    ];

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
     * Standardizes Voltage (e.g., '18v', '54v').
     */
    private static function extractVoltage(array $attrs, string $title): string
    {
        $rawV = self::clean(($attrs['Voltage'] ?? null));
        $searchText = $rawV . ' ' . mb_strtolower($title);
        $match = self::search('\\b(\\d{2,3})\\s?v\\b', $searchText);
        if ($match) {
            return $match->group(1) . 'v';
        }
        $decimalMatch = self::search('\\b(\\d\\.\\d)\\s?v\\b', $searchText);
        if ($decimalMatch) {
            return str_replace('.', '', $decimalMatch->group(1)) . 'v';
        }
        return '';
    }

    /**
     * Detects 'Body Only' vs 'Kit' status.
     */
    private static function isBareTool(array $attrs, string $title): bool
    {
        $t = mb_strtolower(self::clean(($attrs['Type'] ?? null)));
        $titleLow = mb_strtolower($title);
        $bareSignals = ['body only', 'bare tool', 'no battery', 'unit only', 'no charger'];
        return (self::containsAny($titleLow, $bareSignals) ?: self::containsAny($t, $bareSignals));
    }

    /**
     * Detects Brushless motor technology.
     * A critical price anchor: Brushless tools are worth ~30% more.
     */
    private static function isBrushless(array $attrs, string $title): string
    {
        $searchText = mb_strtolower(self::clean(($attrs['Type'] ?? null)) . ' ' . self::clean(($attrs['Features'] ?? null)) . ' ' . self::clean(($attrs['Motor Type'] ?? null)) . ' ' . mb_strtolower($title));
        if (str_contains($searchText, 'brushless')) {
            return 'brushless';
        }
        return '';
    }

    private static function normaliseBrand($raw): string
    {
        $s = mb_strtolower(self::clean($raw));
        if (!$s || (in_array($s, ['unbranded', 'does not apply'], true))) {
            return '';
        }
        return self::alphanumeric($s);
    }

    /**
     * Infer a known power-tool brand from title-only Browse rows.
     */
    private static function brandFromTitle(string $title): string
    {
        $titleLow = mb_strtolower($title);
        foreach (self::TITLE_BRANDS as $brand) {
            if (self::search('\\b' . preg_quote($brand) . '\\b', $titleLow)) {
                return self::alphanumeric($brand);
            }
        }
        return '';
    }

    /**
     * Extracts stable model family.
     */
    private static function normaliseModel($rawModel, string $brand): string
    {
        $s = self::clean($rawModel);
        if (!$s || (str_contains($s, 'does not apply')) || (self::length($s) < 2)) {
            return '';
        }
        $s = self::replacePattern('\\(.*?\\)', '', $s);
        $s = str_replace('\\', ' ', str_replace('-', ' ', str_replace('/', ' ', $s)));
        $tokens = array_values(array_filter(self::words($s), static fn($t) => self::alphanumeric(mb_strtolower($t))));
        if ($tokens && (mb_strtolower($tokens[0]) === $brand)) {
            $tokens = array_slice($tokens, 1);
        }
        return implode('', array_map(static fn($t) => self::alphanumeric(mb_strtolower($t)), array_slice($tokens, 0, 2)));
    }

    /**
     * Extract a compact model family from title-only rows.
     *
     * Browse results often lack structured Brand/Model attributes. For Slack
     * scout rows, title patterns like `Mafell A 12` or `DeWalt DCD796` are strong
     * enough to group sold comps without waiting for richer attributes.
     */
    private static function modelFromTitle(string $title, string $brand): string
    {
        if (!$brand) {
            return '';
        }
        $titleLow = mb_strtolower($title);
        $brandPattern = implode('\\s+', array_map(static fn($part) => preg_quote($part), self::words($brand)));
        $afterBrand = self::search('\\b' . $brandPattern . '\\b\\s+([a-z]{1,4}\\s*[-]?\\s*\\d{1,5}[a-z0-9-]*)', $titleLow);
        if ($afterBrand) {
            return self::alphanumeric($afterBrand->group(1));
        }
        $genericModel = self::search('\\b([a-z]{2,5}\\d{2,5}[a-z0-9-]*)\\b', $titleLow);
        if ($genericModel) {
            return self::alphanumeric($genericModel->group(1));
        }
        return '';
    }

    /**
     * Detect attribute codes that are less useful than explicit title models.
     *
     * Some tool listings expose MPN/catalogue codes such as `91D021` while the
     * title says the trade model family, e.g. `Mafell A 12`. For comp grouping the
     * title family is often the better stable key.
     */
    private static function modelAttrLooksLikeCatalogueCode(string $modelCore): bool
    {
        return (bool)(self::fullMatch('\\d+[a-z]\\d+', ($modelCore ?: '')));
    }

    /**
     * Builds canonical tools key: {brand}-{model}-{voltage}-{motor?}-{bare/kit}
     *
     * Example: "dewalt-dcd996-18v-brushless-bare".
     */
    public static function toolsModelKey(array $attrs, string $title = ''): ?string
    {
        $brand = (self::normaliseBrand(($attrs['Brand'] ?? null)) ?: self::brandFromTitle($title));
        $titleModel = self::modelFromTitle($title, $brand);
        $modelCore = (self::normaliseModel(($attrs['Model'] ?? null), $brand) ?: self::normaliseModel(($attrs['MPN'] ?? null), $brand) ?: self::normaliseModel(($attrs['Type'] ?? null), $brand));
        if ($titleModel && (!$modelCore || self::modelAttrLooksLikeCatalogueCode($modelCore))) {
            $modelCore = $titleModel;
        }
        if (!$brand || !$modelCore) {
            return self::UNKNOWN_KEY;
        }
        $voltage = self::extractVoltage($attrs, $title);
        $motor = self::isBrushless($attrs, $title);
        $bareStatus = (self::isBareTool($attrs, $title) ? 'bare' : 'kit');
        $parts = [$brand, $modelCore];
        if ($voltage) {
            $parts[] = $voltage;
        }
        if ($motor) {
            $parts[] = $motor;
        }
        $parts[] = $bareStatus;
        $baseKey = implode('-', $parts);
        return $baseKey;
    }
}
