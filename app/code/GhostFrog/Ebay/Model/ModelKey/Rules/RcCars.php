<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey\Rules;

use GhostFrog\Ebay\Model\ModelKey\Evidence;

/**
 * Hobby-grade and race RC cars, kits and parts.
 *
 * Keys are rc-{brand}-{kind}-{model}. The kind keeps a whole car apart from a part of the same model,
 * so an Xray T4 wishbone never comps against an Xray T4 kit. Race-looking listings from brands outside
 * the known list are keyed "unknown-review" for a manual check; toy-grade cars are "unknown".
 */
final class RcCars
{
    public const REVIEW = 'unknown-review';

    /** Known race brands, used to identify models rather than to limit collection. */
    private const BRANDS = [
        'schumacher' => 'schumacher',
        'x-?ray' => 'xray',
        'team associated|associated' => 'associated',
        'team losi|tlr|losi' => 'losi',
        'kyosho' => 'kyosho',
        'yokomo' => 'yokomo',
        'mugen(?: seiki)?' => 'mugen',
        'tekno(?: rc)?' => 'tekno',
        'infinity' => 'infinity',
        'awesomatix' => 'awesomatix',
        'serpent' => 'serpent',
        'tamiya|trf' => 'tamiya',
        'sanwa' => 'sanwa',
        'futaba' => 'futaba',
        'hobby ?wing' => 'hobbywing',
        'lrp' => 'lrp',
        'tekin' => 'tekin',
        '(?:team )?orion' => 'orion',
        'savox' => 'savox',
        'hpi(?: racing)?' => 'hpi',
        'traxxas' => 'traxxas',
        'arrma' => 'arrma',
        'thunder tiger' => 'thunder-tiger',
        'ho ?bao' => 'hobao',
        'hot bodies' => 'hot-bodies',
        'team durango|durango' => 'durango',
        'ftx' => 'ftx',
        'maverick' => 'maverick',
        'axial' => 'axial',
        'carisma' => 'carisma',
        'team magic' => 'team-magic',
        'team corally|corally' => 'corally',
        'nikko' => 'nikko',
        'marui' => 'marui',
        'novarossi' => 'novarossi',
        'o\.?s\.? (?:max|engines?|speed)' => 'os',
        'picco' => 'picco',
        'sirio' => 'sirio',
        'reds racing|reds' => 'reds',
        'spektrum' => 'spektrum',
        'flysky|fly ?sky' => 'flysky',
        'muchmore' => 'muchmore',
        'tqi?' => 'tq',
    ];

    /** Model names that carry no digits, checked before the generic model-code match. */
    private const NAMED_MODELS = [
        'mini-?z', 'optima(?: mid)?', 'javelin', 'turbo optima', 'tomahawk', 'scorpion', 'lazer', 'ultima',
        'inferno', 'beetle', 'tomcat', 'hornet', 'grasshopper', 'frog', 'hotshot', 'super hotshot',
        'boomerang', 'sand scorcher', 'rough rider', 'wild one', 'fox', 'avante', 'egress', 'vanquish',
        'super astute', 'astute', 'monster beetle', 'blackfoot', 'thunder shot', 'manta ray', 'fighting buggy',
        'top force', 'dyna storm', 'saint dragon', 'cat', 'top cat', 'procat', 'supercat', 'cougar',
        'fusion', 'mirage', 'storm', 'atom', 'laydown', 'rc10', 'tgx', 'tnx', '8ight(?:-?[xte])?',
        // HPI, Traxxas, Losi, Thunder Tiger, Kyosho and Tamiya names that carry no model code.
        'savage(?: (?:x|xl|flux|25|21))?', 'rush evo', 'nitro rush', 'trophy (?:buggy|truggy|truck)', 'baja 5[bt]',
        'firestorm', 'sprint 2', 'nitro mt2', 'mt2', 'wheely king', 'blitz', 'e-?firestorm',
        't-?maxx', 'e-?maxx', 'revo', 'slash', 'rustler', 'stampede', 'bandit', 'jato', 'nitro sport', 'hoss',
        'dbxl(?:-e)?', 'xxx-?(?:4|t|cr|nt|sct|s)?', 'mini-?t', 'ten-?scte?', 'lst(?: ?xxl)?', 'aftershock',
        'sst', 'ts-?4n', 'ea-?4', 'phoenix', 'mta-?4', 'tomahawk',
        'landmax', 'blizzard', 'quad rider', 'inferno (?:mp|neo|gt|st)\S*', 'mad force', 'mad crusher', 'fazer',
        'holiday buggy', 'bruiser', 'clod buster', 'blackfoot', 'midnight pumpkin', 'super champ', 'falcon',
        'sand rover', 'dual ridge', 'king cab', 'hilux', 'dt-?0[1-3]',
    ];

    private const TOY = '~\b(?:toys?|kids?|children\'?s?|for (?:boys|girls)|boys|girls|ages? \d|'
        . '(?:xmas|christmas|birthday) (?:gift|present)|gift for|stunt car|amphibious|light ?up|transform(?:ing|er)|'
        . 'rechargeable rc car|remote control car for|deerc|bezgar|hosim|sgile|tecnock|mostop|top race)\b~';

    /** Aircraft, boats, helicopters and tanks share engines and radios with cars but not their buyers. */
    private const OUT_OF_SCOPE = '~\b(?:aircraft|aeroplanes?|airplanes?|planes?|helicopters?|heli|boats?|outboard|'
        . 'marine|hydroplane|glider|drone|quadcopter|panzer|flakpanzer|(?<!fuel )tanks?|control line|locomotive)\b~';

    /** Aftermarket parts named after the car they fit ("arms for Losi DBXL") belong to no brand's model. */
    private const FOR_ANOTHER = '~\b(?:for|fits|suits|to fit|compatible with|replacement for)\s+(?:the\s+)?(?:%s)\b~';

    private const RACE = '~\b(?:nitro|glow|brushless|competition|race|racing|racer|touring car|pan car|'
        . 'buggy|truggy|on-?road|1[/:](?:8|10|12)|carbon|cnc|alloy|aluminium|aluminum|lipo|\d{3,5} ?kv|'
        . 'esc|servo|transponder|transmitter|vintage|re-?release|shelf queen|kit|roller|rolling chassis|'
        . 'hpi|traxxas|arrma|team durango|hot bodies|agama|novarossi|picco|reds|sirio|o\.?s\.? engine)\b~';

    private const PART = '~\b(?:parts?|spares? parts|arms?|wishbones?|shocks?|dampers?|diffs?|differential|'
        . 'body ?shell|bodyshell|shell|chassis plate|tyres?|tires?|wheels?|bearings?|gears?|hubs?|'
        . 'turnbuckles?|driveshafts?|upgrades?|set of)\b~';

    /** Tokens that read like model codes but are specs: scale, motor turns, kv, cells, capacity, years. */
    private const SPEC = '~^(?:1[/:]\d+|\d+(?:\.\d+)?(?:wd|kv|t|a|v|s|mah|kg|mm|cc|ghz|mhz|ch|x\d+|g|m|w|nm)|'
        . '(?:19|20)\d\d|\d+(?:st|nd|rd|th))$~';

    public static function key(array $attrs, string $title): string
    {
        $texts = Evidence::texts($attrs, $title, 'Brand', 'Model', 'Type');
        $name = $texts[0];
        if (Evidence::any(self::TOY, [$name]) || preg_match(self::OUT_OF_SCOPE, $name)) {
            return 'unknown';
        }
        [$brand, $offset] = self::brand($attrs, $name);
        if ($brand === '') {
            return Evidence::any(self::RACE, $texts) ? self::REVIEW : 'unknown';
        }
        if (preg_match(sprintf(self::FOR_ANOTHER, implode('|', array_keys(self::BRANDS))), $name)
            && !preg_match('~^(?:' . implode('|', array_keys(self::BRANDS)) . ')\b~', $name)
        ) {
            return 'unknown';
        }
        $model = ($brand === 'tamiya' ? self::tamiyaItem($name) : '')
            ?: self::model(substr($name, $offset)) ?: self::model($name);
        if ($model === '') {
            return self::REVIEW;
        }
        return implode('-', ['rc', $brand, self::kind($attrs, $name), $model]);
    }

    /** @return array{string, int} the brand slug and the title offset just after it */
    private static function brand(array $attrs, string $title): array
    {
        $found = ['', 0, PHP_INT_MAX];
        foreach (self::BRANDS as $pattern => $slug) {
            if (preg_match('~\b(?:' . $pattern . ')\b~', $title, $match, PREG_OFFSET_CAPTURE)
                && $match[0][1] < $found[2]
            ) {
                $found = [$slug, $match[0][1] + strlen($match[0][0]), $match[0][1]];
            }
        }
        if ($found[0] !== '') {
            return [$found[0], $found[1]];
        }
        // Item specifics name the brand when the title does not.
        foreach (Evidence::texts($attrs, '', 'Brand') as $value) {
            foreach (self::BRANDS as $pattern => $slug) {
                if (preg_match('~^(?:' . $pattern . ')\b~', $value)) {
                    return [$slug, 0];
                }
            }
        }
        return ['', 0];
    }

    /** Tamiya numbers every kit and re-release (58xxx cars, 47xxx/84xxx specials), which beats the car's name. */
    private static function tamiyaItem(string $title): string
    {
        return preg_match('~\b(?:item\s*|no\.?\s*|#)?((?:58|47|84)\d{3})\b~', $title, $match) ? $match[1] : '';
    }

    private static function model(string $text): string
    {
        // Scales ("1:10", "1/8") would otherwise read as a version after a named model.
        $text = preg_replace('~\b1\s*[/:]\s*\d{1,2}\b~', ' ', $text) ?? '';
        $text = trim(preg_replace('~[(),|+/]+~', ' ', $text) ?? '');
        $named = implode('|', self::NAMED_MODELS);
        if (preg_match('~\b(' . $named . ')\b(?:\s+([a-z]{0,4}-?\d[a-z0-9.\'-]*))?~', $text, $match)) {
            $code = $match[2] ?? '';
            return self::slug($match[1] . ($code !== '' && !preg_match(self::SPEC, $code) ? ' ' . $code : ''));
        }
        foreach (preg_split('~\s+~', $text) ?: [] as $token) {
            $token = trim($token, ".-'\"");
            // Codes mix letters and digits; Losi-style "22.1" versions are digits only.
            $code = preg_match('~\d~', $token) && preg_match('~[a-z]~', $token) || preg_match('~^\d{2}\.\d$~', $token);
            if ($code && !preg_match(self::SPEC, $token) && strlen($token) <= 16) {
                return self::slug($token);
            }
        }
        return '';
    }

    /** Category tells cars from parts once collected; the title decides for listings without one. */
    private static function kind(array $attrs, string $title): string
    {
        // Every RC path starts "Radio Control & RC Toys", which must not read as a radio part.
        $category = str_replace('radio control & rc toys', '',
            implode(' ', Evidence::texts($attrs, '', 'Browse categoryId', 'Browse categoryPath',
                'Trading PrimaryCategoryID', 'Trading PrimaryCategoryName')));
        $leaf = $category . ' ' . $title;
        return match (true) {
            str_contains($category, '182183') || str_contains($category, 'rc model vehicles & kits') => 'car',
            preg_match('~petrol/nitro engines|\bnitro engine\b|\.(?:12|15|21|28) engine\b~', $leaf) === 1
                => 'engine',
            preg_match('~servo~', $leaf) === 1 => 'servo',
            preg_match('~speed controller|\besc\b~', $leaf) === 1 => 'esc',
            // "RTR buggy with radio" is a car that comes with its transmitter.
            preg_match('~transmitter|receiver|\bradio\b~', $leaf) === 1
                && !preg_match('~\b(?:rtr|buggy|truggy|car|truck|touring)\b~', $title) => 'radio',
            preg_match('~\bmotor\b~', $leaf) === 1 => 'motor',
            // Whole cars are sometimes listed under parts.
            preg_match('~\b(?:kit|rtr|roller|rolling chassis|rc car|nitro car|buggy|truggy|touring car)\b~', $title)
                === 1 && !preg_match(self::PART, $title) => 'car',
            str_contains($category, 'parts & accs') || preg_match(self::PART, $title) === 1 => 'part',
            default => 'car',
        };
    }

    private static function slug(string $value): string
    {
        return trim(preg_replace('~[^a-z0-9]+~', '-', $value) ?? '', '-');
    }
}
