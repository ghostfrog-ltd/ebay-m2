<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey\Rules;

use GhostFrog\Ebay\Model\ModelKey\BrandAliases;
use GhostFrog\Ebay\Model\ModelKey\Evidence;

/**
 * Hobby-grade and race RC cars, kits, electronics, engines and parts.
 *
 * Every listing gets an item type. Keys are rc-{kind}-{brand}-{model}, each segment without hyphens, so a
 * key splits cleanly on "-" ("rc-car-thundertiger-ts4n"). A car at an unusual scale adds a fifth segment
 * ("-s16"), because a 1/16 Slash is not the 1/10 one. The kind is the item type, or for
 * electronics the device (radio, servo, esc, motor), and a vintage item keeps what it is ("vintagecar").
 * Because the kind is in the key, a sold comparable only ever matches listings of the same type.
 *
 * eBay item specifics (Brand, Model, Type, Scale) are read first and the title only fills what they leave
 * out; the listing records which of the two the key came from. Race-looking listings that cannot be keyed
 * are "unknown-review" for a manual check, everything else unkeyed is "unknown".
 */
final class RcCars
{
    public const SOURCE = 'ebay-rc';
    public const REVIEW = 'unknown-review';

    public const ELECTRONICS = 'electronics';
    public const CAR = 'car';
    public const PART = 'part';
    public const VINTAGE = 'vintage';
    public const ENGINE_CAR = 'engine-car';
    public const ENGINE_OTHER = 'engine-other';
    public const NON_CAR = 'non-car-toy';

    /** Item type => label, in the order the Admin lists them. */
    public const TYPES = [
        self::CAR => 'Car, roller or kit',
        self::ELECTRONICS => 'Electronics (radio, servo, ESC, motor)',
        self::PART => 'Part',
        self::VINTAGE => 'Vintage',
        self::ENGINE_CAR => 'Nitro car engine',
        self::ENGINE_OTHER => 'Aircraft, boat or heli engine',
        self::NON_CAR => 'Non-car or toy',
    ];

    public const FROM_SPECIFICS = 'specifics';
    public const FROM_TITLE = 'title';
    public const FROM_BOTH = 'mixed';

    /** Title pattern => canonical brand. The names match the canonical names in the brand alias map. */
    private const BRANDS = [
        'schumacher' => 'Schumacher',
        'x-?ray' => 'Xray',
        'team associated|associated' => 'Team Associated',
        'team losi|tlr|losi' => 'Losi',
        'kyosho' => 'Kyosho',
        'yokomo' => 'Yokomo',
        'mugen(?: seiki)?' => 'Mugen Seiki',
        'tekno(?: rc)?' => 'Tekno',
        'infinity' => 'Infinity',
        'awesomatix' => 'Awesomatix',
        'serpent' => 'Serpent',
        'tamiya|trf' => 'Tamiya',
        'sanwa' => 'Sanwa',
        'futaba' => 'Futaba',
        'hobby ?wing' => 'Hobbywing',
        'lrp' => 'LRP',
        'tekin' => 'Tekin',
        '(?:team )?orion' => 'Team Orion',
        'savox' => 'Savox',
        'hpi(?: racing)?' => 'HPI',
        'traxxas' => 'Traxxas',
        'arrma' => 'Arrma',
        'thunder tiger' => 'Thunder Tiger',
        'ho ?bao' => 'HoBao',
        'hot bodies' => 'Hot Bodies',
        'team durango|durango' => 'Team Durango',
        'ftx' => 'FTX',
        'maverick' => 'Maverick',
        'axial' => 'Axial',
        'carisma' => 'Carisma',
        'team magic' => 'Team Magic',
        'team corally|corally' => 'Corally',
        'nikko' => 'Nikko',
        'marui' => 'Marui',
        'novarossi' => 'Novarossi',
        'o\.?s\.? (?:max|engines?|speed)' => 'O.S.',
        'picco' => 'Picco',
        'sirio' => 'Sirio',
        'reds racing|reds' => 'REDS',
        'spektrum' => 'Spektrum',
        'flysky|fly ?sky' => 'FlySky',
        'muchmore' => 'Muchmore',
        'tqi?' => 'TQ',
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

    private const TOY = '~\b(?:toys?|kids?|children\'?s?|for (?:boys|girls)|boys|girls|'
        . '(?:xmas|christmas|birthday) (?:gift|present)|gift for|stunt car|amphibious|light ?up|transform(?:ing|er)|'
        . 'rechargeable rc car|remote control car for|deerc|bezgar|hosim|sgile|tecnock|mostop|top race|'
        . 'coca[- ]?cola|new bright|tyco|taiyo|radio ?shack|bburago|maisto|hot wheels|paw patrol|disney|barbie)\b~';

    /**
     * Aircraft, boats, helicopters and tanks share engines and radios with cars but not their buyers.
     * A propeller shaft and a fuel tank are car parts, so "propeller" and "tank" only count in the right company.
     */
    private const OUT_OF_SCOPE = '~\b(?:aircraft|aeroplanes?|airplanes?|planes?|aero (?:engine|model)|helicopters?|heli|'
        . 'boats?|watercraft|outboard|marine|hydroplane|water[- ]cooled|yacht|submarine|glider|drone|quadcopter|'
        . 'propellers?(?! ?shaft)|props?(?! ?shaft)\b|panzer|flakpanzer|control line|'
        . '(?:rc|r/c|battle|sherman|tiger|leopard|army|military|toy) tanks?|tanks? (?:toy|model|turret|battle)|'
        . 'c/?l plane|locomotive|(?:4|four)[- ]stroke)\b~';

    /** Aftermarket parts named after the car they fit ("arms for Losi DBXL") belong to no brand's model. */
    private const FOR_CLAUSE = '~\b(?:for|fits|suits|to fit|compatible with|replacement for)\b~';

    private const RACE = '~\b(?:nitro|glow|brushless|competition|race|racing|racer|touring car|pan car|'
        . 'buggy|truggy|on-?road|1[/:](?:8|10|12)|carbon|cnc|alloy|aluminium|aluminum|lipo|\d{3,5} ?kv|'
        . 'esc|servo|transponder|transmitter|vintage|re-?release|shelf queen|kit|roller|rolling chassis|'
        . 'hpi|traxxas|arrma|team durango|hot bodies|agama|novarossi|picco|reds|sirio|o\.?s\.? engine)\b~';

    private const PART_WORDS = '~\b(?:parts?|spares? parts|arms?|wishbones?|shocks?|dampers?|diffs?|differential|'
        . 'body ?shell|bodyshell|shell|chassis plate|tyres?|tires?|wheels?|bearings?|gears?|hubs?|'
        . 'turnbuckles?|driveshafts?|upgrades?|set of)\b~';

    /** Words that name a part outright. Read only from the start of the title, before "with" or "for". */
    private const STRONG_PART = '~\b(?:exhaust|mufflers?|silencers?|manifold|tuned pipe|wishbones?|turnbuckles?|'
        . 'driveshafts?|bearings?|plates?|body ?shells?|bodyshells?|shells?|body(?: set)?|pinions?|spur gears?|gears?|'
        . 'tyres?|tires?|wheels?|hubs?|arms?|shocks?|dampers?|diffs?|differential|shafts?|knuckles?|mounts?|bumpers?|'
        . 'wings?|springs?|king ?pins?|screws?|servo (?:saver|horn)|glow ?plugs?|(?:pull )?starter|recoil|'
        . 'decals?|stickers?|manual|instructions|stand|bag|parts?|spares?|upgrades?|job ?lot|heat ?sink|crank ?case|'
        . '(?:box|frame|chassis) only|empty box|auto ?scale collection|asc|set of)\b~';

    /** A device named with one of these is its fitting or accessory, not the device. */
    private const DEVICE_ACCESSORY = '~\b(?:servo|motor|esc|receiver|radio|transmitter|battery)s? '
        . '(?:mount|horn|saver|plate|fan|box|tray|case|bag|lead|wire|cable|cover|holder|guard|gear|pinion|strap|stand)s?\b~';

    /** Makers whose engines only go in cars. O.S. and Thunder Tiger also build aircraft engines. */
    private const CAR_BRANDS = ['Team Associated', 'Losi', 'Schumacher', 'Xray', 'Mugen Seiki', 'Tekno', 'Serpent', 'Tamiya',
        'HPI', 'Traxxas', 'Arrma', 'HoBao', 'Hot Bodies', 'Team Durango', 'FTX', 'Maverick', 'Axial', 'Carisma',
        'Team Magic', 'Corally', 'Novarossi', 'Sirio', 'REDS', 'LRP', 'Team Orion', 'Kyosho', 'Infinity', 'Dynamite'];

    /** Makers of radios, servos, speed controllers and motors that sell no cars. */
    private const ELECTRONICS_BRANDS = ['Futaba', 'Sanwa', 'Hobbywing', 'Tekin', 'Team Orion', 'Savox', 'Spektrum',
        'FlySky', 'Muchmore'];

    /** Said of a car that is sold whole, whatever else the title mentions. A "sticker kit" is not a car kit. */
    private const COMPLETE = '~\b(?:(?<!sticker |decal |bearing |conversion |upgrade |rebuild |body |screw |hop-up |'
        . 'tuning |repair |service |tool |light |led |spring |gear |diff |shock |wheel |tyre |tire )kit|rtr|arr|artr|'
        . 'ready to run|roller|rolling chassis|unbuilt|expert buil[dt])\b~';

    private const WHOLE_CAR = '~\b(?:rc car|nitro car|buggy|truggy|touring car|monster truck|stadium truck|drift car)\b~';

    private const PART_TYPE = '~\b(?:shells?|body|tires?|tyres?|wheels?|deck|chassis|belt|bearings?|mounts?|set|case|'
        . 'plugs?|starter|governor|parts?|lot|gears?|shocks?|arms?|springs?|decals?|wings?)\b~';

    private const CAR_TYPE = '~\b(?:buggy|trucks?|truggy|touring|drift|formula|rally|crawler|motorcycle|cars?|'
        . 'on-?road|off-?road|model kit|rolling|short course)\b~';

    /** Things sold with or for an engine that are not themselves one. */
    private const ENGINE_ACCESSORY = '~\b(?:glow ?plugs?|plugs?|starter|recoil|mufflers?|silencers?|exhaust|pipe|'
        . 'manifold|crank ?case|crankshaft|carb(?:urett?or)?s?|pistons?|sleeves?|con ?rod|head button|heat ?sink|'
        . 'cooling head|mounts?|gaskets?|bearings?|clutch(?:es)?|flywheel|air filters?|backplate|governor|'
        . 'balancer|test stand|spanner|wrench|tool|gauge|parts)\b~';

    private const CAR_ENGINE = '~\b(?:cars?|buggy|truggy|trucks?|touring|on-?road|off-?road|pull ?start|rear exhaust|'
        . 'monster|novarossi|picco|reds|sirio|rb concept|ninja|lrp|orion|alpha|werks|bullitt|hpi|traxxas|trx|'
        . 'kyosho|ho ?bao|hyper|mugen|dynamite|infinity|tamiya|losi|axial|o\.?s\.? speed)\b~';

    private const DIECAST = '~\b(?:die-?cast|static model|display model|1[/:](?:43|64|87))\b~';

    private const NOT_STATED = '~^(?:n/?a|none|no|does not apply|not applicable|not specified|unknown|other|various|'
        . 'details in description|see description|see photos?|as (?:described|pictured)|[/.\-]+)$~';

    /** Model specifics that name the body or the kind of thing rather than the model. */
    private const NOT_A_MODEL = '~^(?:toyota|ford|honda|subaru|porsche|lotus|nissan|mitsubishi|bmw|audi|mercedes(?:[- ]benz)?|'
        . 'ferrari|lamborghini|volkswagen|vw|mazda|peugeot|renault|alfa(?: romeo)?|lancia|fiat|jeep|land rover|'
        . 'chevrolet|dodge|mclaren|mini|suzuki|chassis|tractor|trucks?|buggy|cars?|body|shell|wheels?|tyres?|tires?|'
        . 'servo|motor|engine|kit|parts?|set|universal|custom|electric|nitro|rc|radio control|4wd|2wd|brushless|'
        . 'brushed|transmitter|receiver|racing|race|touring|vintage|original|standard|front|rear)$~';

    /** Tokens that read like model codes but are specs: scale, motor turns, kv, cells, capacity, years. */
    private const SPEC = '~^(?:1[/:]\d+|\d+(?:\.\d+)?(?:wd|kv|t|a|v|s|mah|kg|mm|cc|ccm|ghz|mhz|ch|x\d+|g|m|w|nm|cm|pcs?)|'
        . '(?:19|20)\d\d|\d+(?:st|nd|rd|th))$~';

    /** Scales where the same model name is also sold at the usual 1/8, 1/10 or 1/12. */
    private const UNUSUAL_SCALES = [5, 6, 16, 18, 24];

    public static function key(array $attrs, string $title, ?BrandAliases $aliases = null): string
    {
        return self::classify($attrs, $title, $aliases)['model_key'];
    }

    /**
     * @return array{model_key: string, item_type: string, key_source: ?string, brand: ?string}
     *         brand is the canonical brand to store, or null to leave the stored brand alone
     */
    public static function classify(array $attrs, string $title, ?BrandAliases $aliases = null): array
    {
        $aliases ??= new BrandAliases();
        $name = Evidence::texts([], $title)[0] ?? '';
        $stated = $aliases->canonical(self::raw($attrs, 'Brand'));
        if ($stated !== '' && preg_match(self::NOT_STATED, mb_strtolower($stated))) {
            $stated = BrandAliases::UNBRANDED;
        }
        // Some sellers paste the title into the Brand field.
        if (strlen($stated) > 30 || str_word_count($stated) > 4) {
            $stated = '';
        }
        [$brand, $brandFrom, $offset] = self::brand($stated, $name);
        [$type, $kind] = self::type($attrs, $name, $brand);
        $result = static fn(string $key, ?string $from = null): array => [
            'model_key' => $key,
            'item_type' => $type,
            'key_source' => $from,
            'brand' => (!str_starts_with($key, 'unknown') ? $brand : $stated) ?: null,
        ];
        // Types kept out of the ranking are not worth flagging as unreadable race listings.
        $unkeyed = in_array($type, [self::NON_CAR, self::ENGINE_OTHER], true) ? 'unknown' : self::REVIEW;
        if ($name === '') {
            return $result('unknown');
        }
        if ($brand === '') {
            $race = Evidence::any(self::RACE, Evidence::texts($attrs, $title, 'Model', 'Type'));
            return $result($race ? $unkeyed : 'unknown');
        }
        if (self::forAnotherBrand($name, $brand, $brandFrom)) {
            return $result('unknown');
        }
        [$model, $modelFrom] = self::modelFor($attrs, $name, $brand, $offset);
        if ($model === '') {
            return $result($unkeyed);
        }
        $scale = self::scale($attrs, $name);
        $key = implode('-', array_filter([
            'rc', $kind, self::slug($brand), $model,
            in_array($kind, [self::CAR, 'vintage' . self::CAR], true) && in_array($scale, self::UNUSUAL_SCALES, true)
                ? 's' . $scale : '',
        ]));
        return $result($key, $brandFrom === $modelFrom ? $brandFrom : self::FROM_BOTH);
    }

    /** @return array{string, string, int} the canonical brand, where it came from and the title offset just after it */
    private static function brand(string $stated, string $title): array
    {
        $found = ['', 0, PHP_INT_MAX];
        foreach (self::BRANDS as $pattern => $canonical) {
            if (preg_match('~\b(?:' . $pattern . ')\b~', $title, $match, PREG_OFFSET_CAPTURE)
                && $match[0][1] < $found[2]
            ) {
                $found = [$canonical, $match[0][1] + strlen($match[0][0]), $match[0][1]];
            }
        }
        if ($stated === '' || $stated === BrandAliases::UNBRANDED) {
            return [$found[0], self::FROM_TITLE, $found[1]];
        }
        // The model usually follows the brand in the title, wherever the brand itself was read from.
        if ($found[0] === $stated) {
            return [$stated, self::FROM_SPECIFICS, $found[1]];
        }
        $at = strpos($title, mb_strtolower($stated));
        return [$stated, self::FROM_SPECIFICS, $at === false ? 0 : $at + strlen($stated)];
    }

    /** True for a part made by one brand to fit another brand's car, which no model's sales describe. */
    private static function forAnotherBrand(string $title, string $brand, string $brandFrom): bool
    {
        $known = implode('|', array_keys(self::BRANDS));
        if (!preg_match_all('~\b(?:for|fits|suits|to fit|compatible with|replacement for)\s+(?:the\s+)?(' . $known . ')\b~',
            $title, $matches)
        ) {
            return false;
        }
        if ($brandFrom === self::FROM_TITLE) {
            return !preg_match('~^(?:' . $known . ')\b~', $title);
        }
        foreach ($matches[1] as $fitted) {
            foreach (self::BRANDS as $pattern => $canonical) {
                if ($canonical === $brand && preg_match('~^(?:' . $pattern . ')$~', $fitted)) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @return array{string, string} the item type and the kind used in the key */
    private static function type(array $attrs, string $title, string $brand): array
    {
        $stated = self::stated($attrs, 'Type');
        $fits = implode(' ', Evidence::texts($attrs, '', 'Compatible Vehicle Type'));
        // Every RC path starts "Radio Control & RC Toys" (or "& Control Line"), which says nothing about the item.
        $category = str_replace(['radio control & rc toys', 'radio control & control line'], '',
            implode(' ', Evidence::texts($attrs, '', 'Browse categoryId', 'Browse categoryPath',
                'Trading PrimaryCategoryID', 'Trading PrimaryCategoryName')));
        $carCategory = str_contains($category, '182183') || str_contains($category, 'rc model vehicles & kits');
        $elsewhere = preg_match(self::OUT_OF_SCOPE, $title . ' | ' . $stated) === 1;

        if (self::isEngine($title, $stated, $carCategory)) {
            // The title outranks the "fits" specific, which eBay often fills in for the seller. With nothing
            // pointing at a car the engine is taken to be an aircraft's: most loose glow engines are.
            $car = match (true) {
                $elsewhere => false,
                preg_match(self::CAR_ENGINE, $title) === 1, in_array($brand, self::CAR_BRANDS, true) => true,
                preg_match(self::OUT_OF_SCOPE, $fits) === 1 => false,
                default => preg_match(self::CAR_ENGINE, $fits) === 1,
            };
            return $car ? [self::ENGINE_CAR, 'enginecar'] : [self::ENGINE_OTHER, 'engineother'];
        }
        $kind = self::kind($title, $stated, $category, $carCategory);
        if ($kind === self::CAR && in_array($brand, self::ELECTRONICS_BRANDS, true)) {
            $kind = self::device($title) ?: self::PART;
        }
        $electronics = !in_array($kind, [self::CAR, self::PART], true);
        // A servo or radio suits any model, so only its own title can place it outside cars.
        if ($elsewhere || (!$electronics && preg_match(self::OUT_OF_SCOPE, $fits))
            || preg_match(self::TOY, $title) || preg_match(self::DIECAST, $title)
            || str_contains(self::stated($attrs, 'Model Grade'), 'toy')
        ) {
            return [self::NON_CAR, 'noncar'];
        }
        if (self::vintage($attrs, $title)) {
            return [self::VINTAGE, 'vintage' . $kind];
        }
        return [$electronics ? self::ELECTRONICS : $kind, $kind];
    }

    /** An engine sold on its own: not a part of one, and not a car that happens to mention its engine. */
    private static function isEngine(string $title, string $stated, bool $carCategory): bool
    {
        $head = self::head($title);
        if (preg_match(self::ENGINE_ACCESSORY, $head)) {
            return false;
        }
        $named = preg_match('~\bengines?\b~', $head) === 1;
        if (preg_match('~stroke engine|moteur|nitro engine|glow engine|^engines?$~', $stated)) {
            return $named || !$carCategory;
        }
        return $named && !$carCategory && !preg_match(self::COMPLETE, $title)
            && !preg_match('~\bengines? (?:car|buggy|truggy|truck)\b(?! (?:boat|plane))~', $head);
    }

    /** car, part, or the electronic device. The item specifics decide first, then the title, then the category. */
    private static function kind(string $title, string $stated, string $category, bool $carCategory): string
    {
        // What the listing is comes before what it is sold with or fits.
        $head = self::head($title);
        $complete = preg_match(self::COMPLETE, $head) === 1;
        $accessory = preg_match(self::DEVICE_ACCESSORY, $head) === 1;
        if (!$complete && !$accessory) {
            $device = self::device($stated) ?: self::device($head);
            // "Radio control buggy" and "brushless buggy" are cars; a servo or receiver never is.
            $certain = preg_match('~servo|\besc\b|speed control|receiver|transmitter~', $stated . ' ' . $head) === 1;
            if ($device !== '' && ($certain || !preg_match(self::WHOLE_CAR, $head))) {
                return $device;
            }
        }
        // The seller's Type ("Buggy", "Touring Car") outranks part words in the title: a car is often listed
        // with the body or wheels it wears.
        $statedCar = preg_match(self::CAR_TYPE, $stated) === 1 && !preg_match(self::PART_TYPE, $stated);
        if ($complete || ($statedCar && !$accessory)) {
            return self::CAR;
        }
        if ($accessory || preg_match(self::STRONG_PART, $head) || preg_match(self::ENGINE_ACCESSORY, $head)
            || preg_match(self::PART_TYPE, $stated)
        ) {
            return self::PART;
        }
        if ($carCategory || preg_match(self::WHOLE_CAR, $title)) {
            return self::CAR;
        }
        // Whole cars are sometimes listed under parts, but nothing here says this is one.
        return str_contains($category, 'parts & accs') || preg_match(self::PART_WORDS, $title) ? self::PART : self::CAR;
    }

    private static function device(string $text): string
    {
        return match (true) {
            str_contains($text, 'servo') => 'servo',
            preg_match('~\besc\b|speed control~', $text) === 1 => 'esc',
            preg_match('~transmitter|receiver|\bradio\b(?! ?control)|\bcontroller\b~', $text) === 1 => 'radio',
            preg_match('~^(?:brushless|brushed)$|\bmotor\b~', $text) === 1 => 'motor',
            default => '',
        };
    }

    /**
     * The title up to the first "with", "for", "no", comma, "+" or "&": the thing itself, without what it
     * comes with, fits or lacks ("RC car, no transmitter" is not a transmitter).
     */
    private static function head(string $title): string
    {
        return preg_split('~\bw/|\b(?:with|w|incl(?:udes|uding)?|plus|needs?|for|fits|suits|no|not|without|less)\b|\s[+&-]\s|,~', $title)[0] ?? '';
    }

    /** The seller's Vintage answer decides when given; otherwise the title does. A re-release is a current kit. */
    private static function vintage(array $attrs, string $title): bool
    {
        $stated = self::stated($attrs, 'Vintage');
        if (preg_match('~re-?release|\brere\b|re-?issue~', $title . ' ' . $stated)) {
            return false;
        }
        if ($stated === 'yes') {
            return true;
        }
        // "no" does not survive stated(), so read the raw answer.
        if (mb_strtolower(self::raw($attrs, 'Vintage')) === 'no') {
            return false;
        }
        // Years are no guide: bodies are named after the car they copy ("Mini Cooper Rally 1994").
        return preg_match('~\b(?:vintage|vtg)\b~', $title) === 1;
    }

    /** @return array{string, string} the model token and where it came from, or two empty strings */
    private static function modelFor(array $attrs, string $title, string $brand, int $offset): array
    {
        if ($brand === 'Tamiya') {
            // Tamiya numbers every kit and re-release (58xxx cars, 47xxx/84xxx specials), which beats the car's name.
            foreach (Evidence::texts($attrs, '', 'Model', 'MPN') as $value) {
                if (preg_match('~^(?:item\s*|no\.?\s*|#)?((?:58|47|84)\d{3})$~', $value, $match)) {
                    return [$match[1], self::FROM_SPECIFICS];
                }
            }
            if (preg_match('~\b(?:item\s*|no\.?\s*|#)?((?:58|47|84)\d{3})\b~', $title, $match)) {
                return [$match[1], self::FROM_TITLE];
            }
        }
        $stated = self::stated($attrs, 'Model');
        if ($stated !== '' && !preg_match(self::FOR_CLAUSE, $stated)) {
            // Sellers often repeat the brand in the Model field.
            $own = trim(str_replace(mb_strtolower($brand), ' ', $stated));
            $model = self::model($own) ?: self::named($own);
            if ($model !== '') {
                return [$model, self::FROM_SPECIFICS];
            }
        }
        // "Wishbone for TT-02" names its car after "for"; anything there is tried last.
        [$before, $after] = array_pad(preg_split(self::FOR_CLAUSE, $title, 2) ?: [], 2, '');
        $model = self::model(substr($before, min($offset, strlen($before)))) ?: self::model($before) ?: self::model($after);
        return $model === '' ? ['', ''] : [$model, self::FROM_TITLE];
    }

    /** A model token from free text: a known model name, else the first token that reads as a model code. */
    private static function model(string $text): string
    {
        // Scales ("1:10", "1/8") would otherwise read as a version after a named model.
        $text = preg_replace('~\b1\s*[/:]\s*\d{1,2}\b~', ' ', $text) ?? '';
        $text = trim(preg_replace('~[(),|+/]+~', ' ', $text) ?? '');
        // Mini-Z cars share a handful of chassis; the body and trim after it change with every release.
        $chassis = preg_match('~\b(m[rabm]|mx|md|fx)-?(0\d{1,2})(?!\d)~', $text, $match) ? $match[1] . $match[2] : '';
        if (preg_match('~\bmini-?z\b~', $text) || preg_match('~^m[rab]0~', $chassis)) {
            return 'miniz' . $chassis;
        }
        // "Sand Scorcher", "sand-scorcher" and "sandscorcher" are one name.
        $named = str_replace(' ', '[ -]?', implode('|', self::NAMED_MODELS));
        if (preg_match('~\b(' . $named . ')\b(?:\s+([a-z]{0,4}-?\d[a-z0-9.\'-]*))?~', $text, $match)) {
            $code = $match[2] ?? '';
            if ($code === '' || preg_match(self::SPEC, $code)) {
                return self::slug($match[1]);
            }
            // "Inferno MP9" and "Lazer ZX-5" are also listed as plain "MP9" and "ZX5", so the code is the model.
            // A bare version ("Fazer Mk2", "Cat L1") means nothing without the name.
            $alone = strlen(self::slug($code)) >= 3 && preg_match('~^[a-z]~', $code)
                && !preg_match('~^(?:mk|v|ver|evo|gen|type)-?\d~', $code);
            return self::slug($alone ? $code : $match[1] . $code);
        }
        foreach (preg_split('~\s+~', $text) ?: [] as $token) {
            $token = trim($token, ".-'\":;!*#");
            // Codes mix letters and digits; Losi-style "22.1" versions are digits only.
            $code = preg_match('~\d~', $token) && preg_match('~[a-z]~', $token) || preg_match('~^\d{2}\.\d$~', $token);
            // A long run of letters is a word with a code stuck to it ("EnginesYO1"), not a model.
            if ($code && !preg_match(self::SPEC, $token) && strlen($token) <= 16 && !preg_match('~[a-z]{6}~', $token)
                && preg_match('~^[a-z0-9.\'-]+$~', $token)
            ) {
                return self::slug($token);
            }
        }
        return '';
    }

    /** A short Model specific that is a name rather than a code ("Thunder Dragon"). */
    private static function named(string $stated): string
    {
        $stated = trim(preg_replace('~\s+~', ' ', $stated) ?? '');
        if (strlen($stated) < 3 || strlen($stated) > 24 || str_word_count($stated) > 3
            || !preg_match('~^[a-z][a-z \'-]*$~', $stated) || preg_match(self::NOT_A_MODEL, $stated)
        ) {
            return '';
        }
        return self::slug($stated);
    }

    private static function scale(array $attrs, string $title): int
    {
        foreach ([self::stated($attrs, 'Scale'), $title] as $text) {
            if (preg_match('~\b1\s*[/:]\s*(\d{1,2})\b~', $text, $match)) {
                return (int)$match[1];
            }
        }
        return 0;
    }

    /** An item specific as the seller typed it; the first value when there are several. */
    private static function raw(array $attrs, string $field): string
    {
        $value = $attrs[$field] ?? '';
        $value = is_array($value) ? (reset($value) ?: '') : $value;
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /** An item specific in lower case, or '' when the seller's answer says nothing ("N/A", "Does not apply"). */
    private static function stated(array $attrs, string $field): string
    {
        $value = Evidence::texts([], self::raw($attrs, $field))[0] ?? '';
        return preg_match(self::NOT_STATED, $value) ? '' : $value;
    }

    /** Lower case with every separator removed, so "TS-4N", "ts 4n" and "B6.4" give "ts4n" and "b64". */
    private static function slug(string $value): string
    {
        return preg_replace('~[^a-z0-9]+~', '', mb_strtolower($value)) ?? '';
    }
}
