<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Test\Unit\Model\ModelKey;

use GhostFrog\Ebay\Model\Comps\Eligibility;
use GhostFrog\Ebay\Model\Comps\TypePolicy;
use GhostFrog\Ebay\Model\ModelKey\AppleDevices;
use GhostFrog\Ebay\Model\ModelKey\AppleMac;
use GhostFrog\Ebay\Model\ModelKey\BrandAliases;
use GhostFrog\Ebay\Model\ModelKey\Classifier;
use GhostFrog\Ebay\Model\ModelKey\KeySummary;
use GhostFrog\Ebay\Model\ModelKey\Rules\RcCars;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RcCarsTest extends TestCase
{
    private const PARTS = 'Toys & Games|Radio Control & RC Toys|RC Model Vehicle Parts & Accs|Other RC Parts & Accs';
    private const ENGINES = 'Petrol/Nitro Engines|Toys & Games|Radio Control & RC Toys|RC Model Vehicle Parts & Accs|'
        . 'Engine, Exhaust & Fuel Systems';

    #[DataProvider('aliases')]
    public function testBrandAliasesIgnoreCaseAndKeepMultiWordBrandsWhole(string $written, string $expected): void
    {
        self::assertSame($expected, (new BrandAliases())->canonical($written));
    }

    public static function aliases(): iterable
    {
        yield 'upper case' => ['TAMIYA', 'Tamiya'];
        yield 'lower case' => ['kyosho', 'Kyosho'];
        yield 'mixed case' => ['YoKoMo', 'Yokomo'];
        yield 'trading name' => ['Tekno RC', 'Tekno'];
        yield 'company name' => ['Futaba Denshi', 'Futaba'];
        yield 'surrounding and doubled spaces' => ['  thunder   tiger ', 'Thunder Tiger'];
        yield 'no brand, upper' => ['NO BRAND', 'Unbranded'];
        yield 'no brand, title case' => ['No Brand', 'Unbranded'];
        yield 'unbranded' => ['Unbranded', 'Unbranded'];
        yield 'not applicable' => ['N/A', 'Unbranded'];
        yield 'French no brand' => ['- Sans marque/Générique -', 'Unbranded'];
        yield 'Thunder Tiger stays whole' => ['Thunder Tiger', 'Thunder Tiger'];
        yield 'Team Associated stays whole' => ['TEAM ASSOCIATED', 'Team Associated'];
        yield 'short name joins the full one' => ['Associated', 'Team Associated'];
        yield 'Mugen Seiki stays whole' => ['mugen seiki', 'Mugen Seiki'];
        yield 'Mugen alone' => ['Mugen', 'Mugen Seiki'];
        yield 'O.S. without dots' => ['OS', 'O.S.'];
        yield 'O.S. Engines' => ['O.S. Engines', 'O.S.'];
        yield 'O.S. itself' => ['o.s.', 'O.S.'];
        // An alias must match the whole brand: "Tiger" on its own is nobody's alias.
        yield 'part of a brand is not an alias' => ['Tiger', 'Tiger'];
        yield 'unlisted brand is kept as written' => ['Eagle Racing', 'Eagle Racing'];
        yield 'empty' => ['  ', ''];
    }

    public function testAliasMapIsReadFromConfigAndReplacesTheDefaults(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->with(BrandAliases::CONFIG_PATH)
            ->willReturn("# comment\nEagle: Eagle Racing, EAGLE MODEL\n\nTamiya");
        $aliases = new BrandAliases($config);

        self::assertSame('Eagle', $aliases->canonical('eagle racing'));
        self::assertSame('Eagle', $aliases->canonical('Eagle Model'));
        self::assertSame('Tamiya', $aliases->canonical('TAMIYA'));
        // Not in the configured map, so no longer merged.
        self::assertSame('Tekno RC', $aliases->canonical('Tekno RC'));
    }

    public function testEmptyConfigFallsBackToTheDefaults(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn(null);

        self::assertSame('Tekno', (new BrandAliases($config))->canonical('Tekno RC'));
    }

    public function testShippedConfigDefaultIsTheSameMapAsTheBuiltInOne(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 4) . '/etc/config.xml');
        $shipped = (string)$xml->default->ghostfrog_ebay->rc->brand_aliases;

        self::assertSame(BrandAliases::parse(BrandAliases::DEFAULTS), BrandAliases::parse($shipped));
        self::assertSame('engine-other,non-car-toy', (string)$xml->default->ghostfrog_ebay->rc->excluded_types);
    }

    #[DataProvider('listings')]
    public function testClassification(string $title, array $attrs, array $expected): void
    {
        $found = RcCars::classify($attrs, $title);

        self::assertSame($expected, array_intersect_key($found, $expected));
    }

    public static function listings(): iterable
    {
        // Thunder Tiger: the brand stays whole in the column and the key, and "tiger" is never the type.
        yield 'Thunder Tiger from the title' => [
            'Rc nitro thunder tiger ts4n 1/10 scale rc car', [],
            ['model_key' => 'rc-car-thundertiger-ts4n', 'item_type' => 'car', 'key_source' => 'title',
                'brand' => 'Thunder Tiger'],
        ];
        yield 'Thunder Tiger from the specifics, hyphenated model' => [
            'Thunder Tiger TS-4N 1:10 4WD Nitro Touring RC Car Skyline Body No Transmitter',
            ['Brand' => 'THUNDER TIGER', 'Type' => 'Touring Car'],
            ['model_key' => 'rc-car-thundertiger-ts4n', 'item_type' => 'car', 'brand' => 'Thunder Tiger'],
        ];
        yield 'Thunder Tiger aircraft engine' => [
            'Thunder Tiger PRO 46 7.5cc 2-Stroke Nitro/Glow RC Aircraft Engine w Muffler',
            ['Brand' => 'Thunder Tiger', 'Type' => '2-Stroke Engine', 'Browse categoryPath' => self::ENGINES],
            ['item_type' => 'engine-other'],
        ];

        // The exhaust pipe that was keyed as a car called "enginesyo1".
        yield 'exhaust pipe listed under cars' => [
            'Kyosho Tuned Muffler Exhaust Pipe for .12/ .15 Size Nitro/Glow EnginesYO1',
            ['Brand' => 'Kyosho', 'Browse categoryId' => '182183'],
            ['model_key' => 'unknown-review', 'item_type' => 'part', 'key_source' => null, 'brand' => 'Kyosho'],
        ];
        yield 'exhaust for a named car is a part of that car' => [
            'Kyosho Tuned Silencer Set for Inferno MP9',
            ['Brand' => 'Kyosho', 'Browse categoryId' => '182183'],
            ['model_key' => 'rc-part-kyosho-mp9', 'item_type' => 'part'],
        ];

        // Aircraft, boat and heli items never join car comparables.
        yield 'engine with propeller' => [
            'Super Tigre GS-40 Nitro Rc Engine w/propeller and muffler! Used',
            ['Brand' => 'SuperTigre', 'Model' => 'Super Tigre GS-40 Nitro', 'Type' => '2-Stroke Engine'],
            ['model_key' => 'rc-engineother-supertigre-gs40', 'item_type' => 'engine-other', 'brand' => 'Super Tigre'],
        ];
        yield 'aircraft engine' => [
            'Enya 19-VI 4006 2-Stroke RC Nitro/Glow Engine with Muffler for Aircraft',
            ['Brand' => 'Enya', 'Type' => '2-Stroke Engine'],
            ['item_type' => 'engine-other'],
        ];
        yield 'aero engine without a model' => [
            'OS Max 46 2-Stroke Nitro/Glow RC Aircraft Engine with Muffler', [],
            ['model_key' => 'unknown', 'item_type' => 'engine-other'],
        ];
        yield 'boat engine' => [
            'K&B 7.5cc .45 Water Cooled RC Boat Outboard Nitro Engine Complete w/ Lower Unit',
            ['Brand' => 'K and B', 'Type' => '2-Stroke Engine'],
            ['item_type' => 'engine-other'],
        ];
        yield 'helicopter engine from a car-engine maker' => [
            'Novarossi 91 Rex Helicopter Engine Needs Piston and Sleeve. Not Working',
            ['Brand' => 'Novarossi', 'Type' => '2-Stroke Engine'],
            ['item_type' => 'engine-other'],
        ];
        yield 'loose glow engine with nothing pointing at a car' => [
            'Irvine 40 ABC Mk IIA RC Nitro Glow Engine w/ Silencer, Paperwork & Taylor Plug',
            ['Brand' => 'Irvine', 'Type' => '2-Stroke Engine'],
            ['item_type' => 'engine-other'],
        ];
        yield 'plane' => [
            'Kyosho Calmate Alpha 60 Sport Trainer RC Airplane EP/GP', ['Brand' => 'Kyosho'],
            ['model_key' => 'unknown', 'item_type' => 'non-car-toy'],
        ];
        yield 'propeller balancer' => [
            'SLEC High-Tec Propeller Balancer E-SL93 for Electric Nitro Glow Petrol',
            ['Brand' => 'SLEC', 'Type' => 'Propeller Balancer'],
            ['item_type' => 'non-car-toy'],
        ];
        yield 'aircraft servo' => [
            'Futaba BLS-A600 S.BUS Brushless Servo for Airplane', ['Brand' => 'Futaba', 'Type' => 'RC Servo'],
            ['item_type' => 'non-car-toy'],
        ];
        yield 'tank' => [
            'Tamiya Flakpanzer Gepard 1/16 RC Tank Radio Control Vintage', ['Brand' => 'Tamiya'],
            ['item_type' => 'non-car-toy'],
        ];
        yield 'toy truck' => [
            'Vintage Coca-Cola Radio Control Trailer Truck Large RC Toy', ['Brand' => 'No Brand'],
            ['model_key' => 'unknown', 'item_type' => 'non-car-toy', 'brand' => 'Unbranded'],
        ];
        yield 'toy grade in the specifics' => [
            'Monster Truck 4WD Off Road 1:16', ['Brand' => 'Unbranded', 'Model Grade' => 'Toy Grade'],
            ['model_key' => 'unknown', 'item_type' => 'non-car-toy'],
        ];
        // The same words in a car's own parts must not push it out.
        yield 'propeller shaft is a car part' => [
            'Tamiya TT-02 Aluminium Propeller Shaft 54501', ['Brand' => 'Tamiya', 'Browse categoryPath' => self::PARTS],
            ['model_key' => 'rc-part-tamiya-tt02', 'item_type' => 'part'],
        ];
        yield 'fuel tank is a car part' => [
            'Kyosho Inferno MP9 Fuel Tank IF473', ['Brand' => 'Kyosho', 'Browse categoryPath' => self::PARTS],
            ['model_key' => 'rc-part-kyosho-mp9', 'item_type' => 'part'],
        ];
        yield 'Mini-Z is a car, not a toy' => [
            'Kyosho Mini-Z MR-03 Sports 2 McLaren P1 GTR', ['Brand' => 'KYOSHO'],
            ['model_key' => 'rc-car-kyosho-minizmr03', 'item_type' => 'car', 'brand' => 'Kyosho'],
        ];

        // Nitro car engines are their own type.
        yield 'car engine by its maker' => [
            'Novarossi Rex RT12 .12 Nitro Engine Brand New',
            ['Brand' => 'Novarossi', 'Type' => '2-Stroke Engine', 'Browse categoryPath' => self::ENGINES],
            ['model_key' => 'rc-enginecar-novarossi-rt12', 'item_type' => 'engine-car'],
        ];
        yield 'car engine by its title' => [
            'O.S. MAX SF 0.12 2-Stroke Nitro/Glow RC Engine Pull Start & Exhaust 1:10',
            ['Brand' => 'O.S.', 'Type' => '2-Stroke Engine', 'Compatible Vehicle Type' => 'Airplanes'],
            ['item_type' => 'engine-car'],
        ];
        yield 'engine part is a part' => [
            'Kyosho 74225-05 Crank Case (GX25) With Ball Bearings For GX25 Nitro Engine',
            ['Brand' => 'Kyosho', 'Browse categoryPath' => self::ENGINES],
            ['item_type' => 'part'],
        ];
        yield 'car that mentions its engine' => [
            'Unbuilt Vintage Tamiya 1/8 R/C TG10 MK1 Rover Mini Cooper Racing FS-12LT Engine',
            ['Brand' => 'Tamiya', 'Browse categoryId' => '182183'],
            ['model_key' => 'rc-vintagecar-tamiya-tg10', 'item_type' => 'vintage'],
        ];

        // Item specifics beat the title, field by field.
        yield 'brand from specifics over the brand in the title' => [
            'Mugen MBX8 buggy roller with Kyosho wheels', ['Brand' => 'Mugen Seiki'],
            ['model_key' => 'rc-car-mugenseiki-mbx8', 'key_source' => 'mixed', 'brand' => 'Mugen Seiki'],
        ];
        yield 'model from specifics over a code in the title' => [
            'Schumacher 1/10 buggy with Savox SC-1251MG fitted', ['Brand' => 'Schumacher', 'Model' => 'Cougar LD3'],
            ['model_key' => 'rc-car-schumacher-ld3', 'key_source' => 'specifics'],
        ];
        yield 'named model from specifics when the title has none' => [
            'Tamiya 1/10 4WD Electric Brushed RC Off-Road Buggy', ['Brand' => 'TAMIYA', 'Model' => 'Thunder Dragon'],
            ['model_key' => 'rc-car-tamiya-thunderdragon', 'key_source' => 'specifics', 'brand' => 'Tamiya'],
        ];
        yield 'Tamiya kit number from the MPN specific' => [
            'Tamiya Hornet 1/10 buggy kit new in box', ['Brand' => 'Tamiya', 'MPN' => '58336'],
            ['model_key' => 'rc-car-tamiya-58336', 'key_source' => 'specifics'],
        ];
        yield 'type from specifics over the title' => [
            'Sanwa M17 with RX-493i', ['Brand' => 'Sanwa', 'Type' => 'Transmitter', 'Browse categoryId' => '182183'],
            ['model_key' => 'rc-radio-sanwa-m17', 'item_type' => 'electronics'],
        ];
        yield 'unusual scale from specifics' => [
            'Traxxas Slash 4x4 VXL brushless', ['Brand' => 'Traxxas', 'Scale' => '1:16', 'Type' => 'Truck'],
            ['model_key' => 'rc-car-traxxas-slash-s16'],
        ];
        yield 'usual scale stays out of the key' => [
            'Traxxas Slash 4x4 VXL brushless', ['Brand' => 'Traxxas', 'Scale' => '1:10', 'Type' => 'Truck'],
            ['model_key' => 'rc-car-traxxas-slash'],
        ];
        // The title fills only what the specifics leave out.
        yield 'specifics that say nothing fall back to the title' => [
            'Xray T4 2021 touring car roller', ['Brand' => 'N/A', 'Model' => 'NA', 'Type' => 'Does not apply'],
            ['model_key' => 'rc-car-xray-t4', 'key_source' => 'title', 'brand' => 'Xray'],
        ];
        yield 'model specific that names the body, not the model' => [
            'Tamiya TT-02 Toyota GR86 kit', ['Brand' => 'Tamiya', 'Model' => 'Toyota'],
            ['model_key' => 'rc-car-tamiya-tt02', 'key_source' => 'mixed'],
        ];
        yield 'no specifics at all, as at collection' => [
            'Team Associated B6.4 Team Kit 1/10 2WD Buggy', ['Browse categoryId' => '182183'],
            ['model_key' => 'rc-car-teamassociated-b64', 'key_source' => 'title', 'brand' => 'Team Associated'],
        ];
        yield 'a brand eBay has not seen is still keyed from the specifics' => [
            'PR Racing S1 V3 1/10 4wd buggy', ['Brand' => 'PR Racing'],
            ['model_key' => 'rc-car-prracing-s1', 'brand' => 'PR Racing'],
        ];
        yield 'title pasted into the brand field is ignored' => [
            'Serpent Medius X20 1/10 4WD Electric Touring Car rolling chassis',
            ['Brand' => 'Serpent Medius X20 1/10 4WD Electric Touring Car Orange'],
            ['model_key' => 'rc-car-serpent-x20', 'key_source' => 'title', 'brand' => 'Serpent'],
        ];

        // Vintage overrides car, part and electronics; engines and toys stay what they are.
        yield 'vintage car by the seller\'s answer' => [
            'Schumacher Procat Rc Buggy', ['Brand' => 'Schumacher', 'Vintage' => 'Yes', 'Type' => 'Buggy'],
            ['model_key' => 'rc-vintagecar-schumacher-procat', 'item_type' => 'vintage'],
        ];
        yield 'vintage part by the title' => [
            'Vintage Kyosho Optima rear gearbox casing parts', ['Brand' => 'Kyosho', 'Browse categoryPath' => self::PARTS],
            ['model_key' => 'rc-vintagepart-kyosho-optima', 'item_type' => 'vintage'],
        ];
        yield 'vintage electronics' => [
            'Vintage Futaba Attack-R T2NBR RC Radio Transmitter Boxed', ['Brand' => 'Futaba', 'Type' => 'RC Transmitter'],
            ['model_key' => 'rc-vintageradio-futaba-t2nbr', 'item_type' => 'vintage'],
        ];
        yield 'vintage car engine stays a car engine' => [
            'RB Concept TM323 Nitro Engine Rare Vintage', ['Brand' => 'RB', 'Model' => 'TM323', 'Vintage' => 'Yes'],
            ['model_key' => 'rc-enginecar-rb-tm323', 'item_type' => 'engine-car'],
        ];
        yield 'vintage aircraft engine stays an aircraft engine' => [
            'Vintage Fox 19 RC 2-Stroke Glow/Nitro Airplane Engine w/ Wood Prop', ['Brand' => 'Fox', 'Vintage' => 'Yes'],
            ['item_type' => 'engine-other'],
        ];
        yield 'vintage engine from a car maker' => [
            'BRAND NEW HPI Nitro Star F4.6 Engine Vintage Rare', ['Brand' => 'HPI', 'Vintage' => 'Yes'],
            ['model_key' => 'rc-enginecar-hpi-f46', 'item_type' => 'engine-car'],
        ];
        yield 're-release is a current kit' => [
            'Tamiya Hornet re-release 58336 boxed', ['Brand' => 'Tamiya', 'Vintage' => 'Yes'],
            ['model_key' => 'rc-car-tamiya-58336', 'item_type' => 'car'],
        ];
        yield 'seller says not vintage' => [
            'Vintage style Tamiya Hornet 58336', ['Brand' => 'Tamiya', 'Vintage' => 'No'],
            ['item_type' => 'car'],
        ];
        yield 'a year in a body name is not vintage' => [
            'Tamiya 58483 Mini Cooper Rally 1994 RC M-05 Chassis Kit', ['Brand' => 'Tamiya'],
            ['model_key' => 'rc-car-tamiya-58483', 'item_type' => 'car'],
        ];

        // Electronics are keyed by device, so a servo never comps against a radio of the same name.
        yield 'servo' => [
            'Savox SB-2290SG Brushless Servo', ['Browse categoryPath' => self::PARTS],
            ['model_key' => 'rc-servo-savox-sb2290sg', 'item_type' => 'electronics'],
        ];
        yield 'speed controller named RTR' => [
            'Hobbywing 1060 60A Brushed ESC NIMH / LIPO THW-1060 RTR', ['Brand' => 'HOBBYWING', 'Browse categoryId' => '182183'],
            ['model_key' => 'rc-esc-hobbywing-thw1060', 'item_type' => 'electronics', 'brand' => 'Hobbywing'],
        ];
        yield 'car sold without its transmitter' => [
            'Kyosho Fazer Mk2 RC Car New US: No Transmitter', ['Brand' => 'Kyosho', 'Browse categoryId' => '182183'],
            ['model_key' => 'rc-car-kyosho-fazermk2', 'item_type' => 'car'],
        ];
        yield 'servo mount is a part' => [
            'Xray T4 alloy servo mount', ['Brand' => 'Xray', 'Browse categoryPath' => self::PARTS],
            ['model_key' => 'rc-part-xray-t4', 'item_type' => 'part'],
        ];
    }

    #[DataProvider('spellings')]
    public function testModelTokensIgnoreCaseHyphensSpacesAndDots(string $one, string $other, string $key): void
    {
        self::assertSame($key, RcCars::key([], $one));
        self::assertSame($key, RcCars::key([], $other));
    }

    public static function spellings(): iterable
    {
        yield 'TS-4N' => ['Thunder Tiger TS-4N nitro touring car', 'thunder tiger ts4n touring car', 'rc-car-thundertiger-ts4n'];
        yield 'TT-02' => ['Tamiya TT-02 chassis kit', 'TAMIYA TT02 kit', 'rc-car-tamiya-tt02'];
        yield 'B6.4' => ['Team Associated B6.4 Team Kit', 'team associated b64 kit', 'rc-car-teamassociated-b64'];
        yield 'YD-2' => ['Yokomo YD-2 drift car kit', 'Yokomo YD2 drift car kit', 'rc-car-yokomo-yd2'];
        yield 'named model with a code' => ['Kyosho Inferno MP9 buggy kit', 'Kyosho MP-9 buggy kit', 'rc-car-kyosho-mp9'];
        yield 'two-word named model' => ['Tamiya Sand Scorcher kit', 'TAMIYA SAND-SCORCHER KIT', 'rc-car-tamiya-sandscorcher'];
    }

    public function testEveryKeySplitsIntoKindBrandAndModel(): void
    {
        foreach (self::listings() as $name => [$title, $attrs]) {
            $found = RcCars::classify($attrs, $title);
            self::assertArrayHasKey($found['item_type'], RcCars::TYPES, $name);
            if (str_starts_with($found['model_key'], 'unknown')) {
                self::assertNull($found['key_source'], $name);
                continue;
            }
            self::assertMatchesRegularExpression('~^rc-[a-z]+-[a-z0-9]+-[a-z0-9]+(?:-s\d+)?$~', $found['model_key'], $name);
            self::assertContains($found['key_source'], ['specifics', 'title', 'mixed'], $name);
        }
    }

    public function testClassifierReturnsTheColumnsToStoreAndIgnoresItsOwnStoredBrand(): void
    {
        $classifier = new Classifier(new AppleMac(), new AppleDevices(), new BrandAliases());
        $row = ['title' => 'TAMIYA TT-02 kit', 'raw_attrs' => '{"Brand":"TAMIYA"}', 'brand' => 'TAMIYA', 'model_name' => null];

        $first = $classifier->columnsForListing('ebay-rc', $row);
        self::assertSame(['model_key' => 'rc-car-tamiya-tt02', 'item_type' => 'car', 'key_source' => 'mixed',
            'brand' => 'Tamiya'], $first);
        // Storing the result and classifying again changes nothing, which is what makes the backfill re-runnable.
        self::assertSame($first, $classifier->columnsForListing('ebay-rc', $first + $row));

        // With no Brand specific the stored brand column is the classifier's own earlier answer, not evidence.
        $titleOnly = ['title' => 'Tamiya TT-02 kit', 'raw_attrs' => '{}', 'brand' => 'Tamiya'];
        self::assertSame('title', $classifier->columnsForListing('ebay-rc', $titleOnly)['key_source']);

        // Other sources still return only a key.
        self::assertSame(['model_key'], array_keys($classifier->columnsForListing('ebay-ipod',
            ['title' => 'Apple iPod Classic 7th Generation 160GB', 'raw_attrs' => '{}'])));
    }

    public function testComparablesOnlyMatchWithinOneItemType(): void
    {
        $kit = RcCars::key(['Browse categoryId' => '182183'], 'Xray T4 2021 touring car kit');
        $part = RcCars::key(['Browse categoryPath' => self::PARTS], 'Xray T4 front suspension arms graphite');
        $vintage = RcCars::key(['Vintage' => 'Yes'], 'Xray T4 touring car kit');

        self::assertSame(['rc-car-xray-t4', 'rc-part-xray-t4', 'rc-vintagecar-xray-t4'], [$kit, $part, $vintage]);
    }

    public function testRcKeepsReplacementPartsButStillDropsFaultyDescriptions(): void
    {
        $eligibility = new Eligibility(new Classifier(new AppleMac(), new AppleDevices(), new BrandAliases()));
        $attrs = ['Brand' => 'Xray', 'Browse categoryPath' => self::PARTS];
        $listing = static fn(array $extra, string $source = 'ebay-rc'): array => [
            'source' => $source, 'model_key' => 'rc-part-xray-t4', 'condition_grade' => null,
            'title' => 'Xray T4 front suspension arms graphite',
            'raw_attrs' => json_encode($attrs + $extra),
        ];

        self::assertSame(['rc-part-xray-t4', 'B'], $eligibility->group($listing([])));
        self::assertSame(['rc-part-xray-t4', 'B'],
            $eligibility->group($listing(['Trading Description' => 'Genuine Xray replacement part, new in packet.'])));
        // A fault mentioned only in the description leaves the listing out of working and faulty comps alike.
        self::assertNull($eligibility->group($listing(['Trading Description' => 'Sold as spares or repair, one arm cracked.'])));
        self::assertNull($eligibility->group($listing(['Trading Description' => 'Not working, untested.'])));
        // Other sources keep the replacement-part exclusion.
        self::assertNull($eligibility->group([
            'source' => 'ebay-apple', 'model_key' => 'apple-macbook-air-a2337-133in-m1-8gb-256gb', 'condition_grade' => 'B',
            'title' => 'MacBook Air M1 13" 8GB RAM 256GB SSD',
            'raw_attrs' => '{"Screen Size":"13.3 in","Trading Description":"Fitted with a replacement battery."}',
        ]));
    }

    public function testOnlyTheExcludedTypesStayOutOfTheRanking(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->with(TypePolicy::EXCLUDED_PATH)->willReturn('engine-other, non-car-toy');
        $policy = new TypePolicy($config);

        self::assertTrue($policy->isExcluded('engine-other'));
        self::assertTrue($policy->isExcluded('non-car-toy'));
        // Vintage and parts are priced like every other listing.
        foreach (['car', 'electronics', 'part', 'vintage', 'engine-car'] as $type) {
            self::assertFalse($policy->isExcluded($type), $type);
        }
        // Sources without item types are priced as before.
        self::assertFalse($policy->isExcluded(null));
    }

    public function testKeySummaryCountsFragmentation(): void
    {
        $summary = new KeySummary();
        foreach (['rc-car-a-1', 'rc-car-a-1', 'rc-car-a-1', 'rc-car-b-2', 'unknown', 'unknown-review', '', null] as $key) {
            $summary->add($key, $key === 'rc-car-b-2' ? 'vintage' : 'car', str_starts_with((string)$key, 'rc') ? 'title' : null);
        }

        self::assertSame(['rows' => 8, 'keyed' => 4, 'unknown' => 4, 'unknown_percent' => 50.0, 'distinct' => 2,
            'singletons' => 1, 'shared' => 3, 'five_plus' => 0], $summary->totals());
        self::assertSame(['car' => 7, 'vintage' => 1], $summary->types());
        self::assertSame(['title' => 4], $summary->sources());
        self::assertSame(['rc-car-a-1' => 3, 'rc-car-b-2' => 1], $summary->top());
    }
}
