<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey\Rules;

/**
 * Single video games, keyed game-{platform}-{name}[-{edition}][-loose][-{region}],
 * for example game-switch-mario-party-superstars or game-n64-armorines-project-swarm-loose.
 *
 * The name comes from the Game Name item specific, which sellers fill in loosely (series names,
 * genres, "Does not apply"), so it only counts when every word of it also appears in the title.
 * The title decides the platform, because the Platform specific often lists every system a disc
 * plays on. PAL is the default region on eBay UK; Japanese and US copies sell apart and get a suffix.
 * Bundles, graded copies, codes, boxes, manuals and anything with console hardware stay unknown.
 */
final class Games
{
    /** Checked in order; earlier entries win where patterns overlap ("super famicom" before "famicom"). */
    private const PLATFORMS = [
        'switch-2' => 'nintendo switch ?2|switch ?2\b|\bns2\b',
        'switch' => '\bswitch\b',
        'wii-u' => '\bwii ?u\b',
        'wii' => '\bwii\b',
        'gamecube' => 'game ?cube|\bngc\b',
        'n64' => 'nintendo 64|\bn64\b',
        'snes' => '\bsnes\b|super nintendo|super famicom|\bsfc\b',
        'nes' => '\bnes\b|(?<!super )famicom',
        '3ds' => '\b(?:new )?(?:nintendo )?[23]ds\b',
        'ds' => '\bds\b|\bnds\b|ds lite',
        'gba' => 'game ?boy advance|\bgba\b',
        'gbc' => 'game ?boy colou?r|\bgbc\b',
        'gameboy' => 'game ?boy(?! advance| colou?r)',
        'ps5' => '\bps ?5\b|playstation ?5',
        'ps4' => '\bps ?4\b|playstation ?4',
        'ps3' => '\bps ?3\b|playstation ?3',
        'ps2' => '\bps ?2\b|playstation ?2',
        'ps1' => '\bps ?1\b|\bpsx\b|\bps ?one\b|playstation ?1\b',
        'psp' => '\bpsp\b|playstation portable',
        'vita' => '\b(?:ps |playstation )?vita\b',
        'xbox-series' => 'xbox series|series [xs]\b',
        'xbox-one' => 'xbox one',
        'xbox-360' => 'xbox ?360',
        'xbox' => '\bxbox\b(?! ?(?:one|360|series|live|wireless|elite|controller|game pass))',
        'megadrive' => 'mega ?drive|sega genesis',
        'master-system' => 'master system',
        'saturn' => 'sega saturn',
        'dreamcast' => 'dreamcast',
        'pc' => '\bpc\b(?! engine)',
    ];

    /** Phrases that name a second system a disc merely runs on or upgrades to. */
    private const COMPATIBLE = '~\b(?:playable on|compatible with|works on|upgrade to|free upgrade|backwards compatible)\b'
        . '[^,|/()]{0,25}|\b(?:ps ?5|xbox series [xs]|switch 2)\s+(?:compatible|upgrade|playable)\b~';

    private const LOT = '~\b(?:bundle|bundles|job ?lot|joblot|lot of|games? lot|collection of|\d+\s*x\s+\w|'
        . '\d+ games|games x|set of|set \d+|\d+ set)\b|\bx\d+\b~';

    private const HARDWARE = '~\bconsole(?! game)\b|\b\d+\s?(?:gb|tb)\b|\bcontrollers?\b|\bhandheld\b|'
        . '\bjoy-?cons?\b|\bheadset\b|\bsteering wheel\b~';

    private const JUNK = '~\b(?:manual only|case only|box only|cover only|inlay|artwork|poster|strategy guide|guide ?book|'
        . 'art ?book|soundtrack|steelbook only|replacement case|no game|empty|disc \d of \d|download code|digital code|'
        . 'code only|code in a box|code-in-a-box|dlc|voucher|amiibo|figure|figurine|reproduction|repro|'
        . 'wata|vga|cgc|ukg|graded|psa \d|not working|faulty|damaged disc|cracked)\b~';

    private const EDITIONS = [
        'collectors' => "collector'?s(?: edition)?",
        'limited' => 'limited (?:run|edition)',
        'special' => 'special edition',
        'steelbook' => 'steel ?book',
        'goty' => 'game of the year|\bgoty\b',
        'deluxe' => 'deluxe edition',
        'ultimate' => 'ultimate edition',
        'gold' => 'gold edition',
        'complete' => 'complete edition',
        'definitive' => 'definitive edition',
        // Budget reprints sell below first prints.
        'budget' => "\bplatinum\b|\bessentials\b|greatest hits|players'? choice|nintendo selects",
    ];

    private const LOOSE = '~\b(?:cart(?:ridge)? only|disc only|disk only|game only|loose|unboxed|no (?:box|case))\b~';

    /** Series names sellers put in Game Name when the listing is one game of many. */
    private const FRANCHISES = ['pokemon', 'mario', 'zelda', 'fifa', 'lego', 'sonic', 'dragonball', 'starwars',
        'callofduty', 'finalfantasy', 'needforspeed', 'kirby', 'metroid', 'halo', 'gta', 'yakuza', 'persona',
        'residentevil', 'tomclancy', 'assassinscreed', 'crash', 'spyro', 'nba', 'nfl', 'wwe', 'f1', 'pes',
        'ratchetandclank', 'grandtheftauto', 'legendofzelda', 'supermario', 'kingdomhearts', 'metalgearsolid',
        'granturismo', 'mariokart', 'streetfighter', 'tekken', 'mortalkombat', 'battlefield', 'farcry', 'uncharted',
        'thesims', 'sims', 'disney', 'marvel', 'harrypotter', 'ufc', 'nhl', 'madden', 'easportsfc', 'wrc', 'motogp'];

    private const REGION_WORDS = 'ntsc ?[ju](?: ?c)?|ntsc|pal|japan|japanese|jpn|import|uk|usa|eur?|version|ver';

    private const NOT_A_NAME = ['does not apply', 'n a', 'na', 'none', 'unknown', 'not applicable', 'various',
        'multiple', 'see description', 'game', 'video game'];

    public static function key(array $attrs, string $title): string
    {
        $title = self::fold($title);
        if ($title === '' || preg_match(self::LOT, $title) || preg_match(self::HARDWARE, $title)
            || preg_match(self::JUNK, $title)
        ) {
            return 'unknown';
        }
        $name = self::name($attrs, $title);
        $platform = $name === '' ? '' : self::platform($attrs, $title);
        if ($platform === '') {
            return 'unknown';
        }
        $editions = [];
        $original = self::fold(self::rawName($attrs));
        foreach (self::EDITIONS as $slug => $pattern) {
            if (preg_match('~' . $pattern . '~', $title) || preg_match('~' . $pattern . '~', $original)) {
                $editions[] = $slug;
            }
        }
        return implode('-', array_filter([
            'game',
            $platform,
            $name,
            implode('-', $editions),
            preg_match(self::LOOSE, $title) ? 'loose' : '',
            self::region($attrs, $title),
        ]));
    }

    /** The Game Name specific as a slug, when every word of it also appears in the title. */
    private static function name(array $attrs, string $title): string
    {
        // Text in brackets is platform or import notes ("Rise of the Ronin (PS5)", "[Multilingual]").
        $value = trim((string)preg_replace('~\s*[(\[].*$~u', '', self::rawName($attrs)));
        if ($value === '') {
            return '';
        }
        $name = self::fold($value);
        // Editions become a suffix, so "Deluxe Edition" in the name and in the title give one key.
        foreach (self::EDITIONS as $pattern) {
            $name = (string)preg_replace('~' . $pattern . '~', ' ', $name);
        }
        $name = (string)preg_replace('~\b(?:standard|legacy|day one|launch)? ?edition\b~', ' ', $name);
        // Sellers often type the platform and region into the name ("PS2 Robocop Japan PlayStation 2").
        foreach (self::PLATFORMS as $pattern) {
            $name = (string)preg_replace('~\b(?:nintendo |sony |sega |microsoft )?(?:' . $pattern . ')\b~', ' ', $name);
        }
        $name = (string)preg_replace('~\b(?:' . self::REGION_WORDS . '|game|video game)\b~', ' ', $name);
        $name = trim((string)preg_replace(['~\s+~', '~^the\s+~'], [' ', ''], trim($name)));
        if ($name === '' || in_array($name, self::NOT_A_NAME, true)) {
            return '';
        }
        $tokens = array_values(array_filter(explode(' ', $name), 'strlen'));
        // Some sellers paste the name twice.
        $half = intdiv(count($tokens), 2);
        if ($half > 0 && count($tokens) % 2 === 0 && array_slice($tokens, 0, $half) === array_slice($tokens, $half)) {
            $tokens = array_slice($tokens, 0, $half);
        }
        $titleTokens = array_flip(explode(' ', $title));
        $missing = array_filter($tokens, static fn (string $token): bool => !isset($titleTokens[$token]));
        // A long name may differ by one word ("Minecraft Story Mode A Telltale Series").
        if ($tokens === [] || count($missing) > (count($tokens) >= 4 ? 1 : 0)) {
            return '';
        }
        $series = self::fold(is_string($attrs['Video Game Series'] ?? null) ? $attrs['Video Game Series'] : '');
        if (count($tokens) === 1 && (in_array($tokens[0], self::FRANCHISES, true) || $tokens[0] === $series)) {
            return '';
        }
        if (in_array(implode('', $tokens), self::FRANCHISES, true)) {
            return '';
        }
        return implode('-', $tokens);
    }

    private static function rawName(array $attrs): string
    {
        $value = $attrs['Game Name'] ?? $attrs['Video Game Name'] ?? '';
        if (is_array($value)) {
            $value = count($value) === 1 ? reset($value) : '';
        }
        return is_string($value) ? $value : '';
    }

    private static function platform(array $attrs, string $title): string
    {
        $found = self::platforms((string)preg_replace(self::COMPATIBLE, ' ', $title));
        if (count($found) === 1) {
            return $found[0];
        }
        if (count($found) > 1) {
            return '';
        }
        $values = $attrs['Platform'] ?? [];
        $values = is_array($values) ? $values : [$values];
        $platforms = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                array_push($platforms, ...self::platforms(self::fold($value)));
            }
        }
        $platforms = array_values(array_unique($platforms));
        return count($platforms) === 1 ? $platforms[0] : '';
    }

    /** @return string[] */
    private static function platforms(string $text): array
    {
        $found = [];
        foreach (self::PLATFORMS as $slug => $pattern) {
            // Whole words only, so "Nintendo Switch 2022" stays a Switch game.
            $pattern = '~\b(?:' . $pattern . ')\b~';
            if (preg_match($pattern, $text)) {
                $found[] = $slug;
                // Remove the match so "switch 2" does not also read as "switch", nor "3ds" as "ds".
                $text = (string)preg_replace($pattern, ' ', $text);
            }
        }
        return $found;
    }

    private static function region(array $attrs, string $title): string
    {
        if (preg_match('~\b(?:ntsc ?j|japan|japanese|jpn|jp ver(?:sion)?|jap import)\b~', $title)) {
            return 'jp';
        }
        if (preg_match('~\b(?:ntsc ?u(?: ?c)?|ntsc|us import|usa|us version|us ver)\b~', $title)) {
            return 'us';
        }
        if (preg_match('~\bpal\b~', $title)) {
            return '';
        }
        $code = self::fold(is_string($attrs['Region Code'] ?? null) ? $attrs['Region Code'] : '');
        return match (true) {
            (bool)preg_match('~ntsc ?j|japan~', $code) => 'jp',
            (bool)preg_match('~ntsc ?u|us canada~', $code) => 'us',
            default => '',
        };
    }

    /** Lower-case ASCII words separated by single spaces; repairs double-encoded UTF-8 ("PokÃ©mon"). */
    private static function fold(string $text): string
    {
        if (preg_match('~Ã.~u', $text)) {
            $repaired = mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
            if (mb_check_encoding($repaired, 'UTF-8')) {
                $text = $repaired;
            }
        }
        if (function_exists('transliterator_transliterate')) {
            $text = (string)transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
        }
        $text = mb_strtolower($text);
        $text = str_replace(['&', '+'], [' and ', ' and '], $text);
        // "Tom Clancy's H.A.W.X." and "Tom Clancys HAWX" are the same game.
        $text = (string)preg_replace("~['’`.]~u", '', $text);
        $text = (string)preg_replace('~[^a-z0-9]+~', ' ', $text);
        return trim((string)preg_replace('~\s+~', ' ', $text));
    }
}
