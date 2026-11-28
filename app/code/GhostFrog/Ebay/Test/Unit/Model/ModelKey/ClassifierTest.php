<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Test\Unit\Model\ModelKey;

use GhostFrog\Ebay\Model\Collection\SourceSearches;
use GhostFrog\Ebay\Model\ModelKey\AppleDevices;
use GhostFrog\Ebay\Model\ModelKey\AppleMac;
use GhostFrog\Ebay\Model\ModelKey\Classifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClassifierTest extends TestCase
{
    public function testEveryCollectorSourceHasAnIdentityFixture(): void
    {
        $sources = array_keys(SourceSearches::DEFINITIONS);
        $fixtures = array_keys(iterator_to_array(self::sourceCases()));
        sort($sources);
        sort($fixtures);
        self::assertSame($sources, $fixtures);
    }

    #[DataProvider('sourceCases')]
    #[DataProvider('guardCases')]
    public function testModelKey(string $source, string $title, array $attrs, string $expected): void
    {
        $classifier = new Classifier(new AppleMac(), new AppleDevices());
        self::assertSame($expected, $classifier->key($source, $title, $attrs));
    }

    public function testConsoleListingsSayWhetherTheyAreAnAccessory(): void
    {
        $classifier = new Classifier(new AppleMac(), new AppleDevices());
        self::assertSame(['model_key' => 'accessory-ps5-controller-official', 'is_accessory' => '1'],
            $classifier->columns('ebay-consoles', 'Sony PS5 DualSense Wireless Controller White', []));
        self::assertSame('1', $classifier->columns('ebay-consoles', '2x Xbox One Controllers', [])['is_accessory']);
        self::assertSame('0', $classifier->columns('ebay-consoles', 'Xbox Series X 1TB Console', [])['is_accessory']);
        self::assertArrayNotHasKey('is_accessory', $classifier->columns('ebay-lego', 'LEGO 75192 Millennium Falcon', []));
    }

    public static function sourceCases(): iterable
    {
        yield 'ebay-actioncams' => [
            'ebay-actioncams',
            'AKASO EK7000 Pro 4K Action Camera',
            [
                'Brand' => 'AKASO',
                'Model' => 'EK7000 Pro',
            ],
            'akaso-ek7000-pro',
        ];
        yield 'ebay-apple' => [
            'ebay-apple',
            'MacBook Air M1 13" 8GB RAM 256GB SSD',
            [
                'Screen Size' => '13.3 in',
            ],
            'apple-macbook-air-a2337-133in-m1-8gb-256gb',
        ];
        yield 'ebay-ipod' => [
            'ebay-ipod',
            'Apple iPod Classic 7th Gen 160GB Silver - New Battery',
            [],
            'apple-ipod-classic-7th-gen-160gb',
        ];
        yield 'ebay-rc' => [
            'ebay-rc',
            'Team Associated B6.4 Team Kit 1/10 2WD Buggy',
            ['Browse categoryId' => '182183'],
            'rc-car-teamassociated-b64',
        ];
        yield 'ebay-camera-lenses' => [
            'ebay-camera-lenses',
            'Sony FE 85mm F1.4 GM Lens',
            [],
            'sony-fe-85mm-f14-gm',
        ];
        yield 'ebay-consoles' => [
            'ebay-consoles',
            'Xbox Series X 1tb, Controller Is Power A Wired, No Box',
            [
                'brand' => 'Microsoft',
                'model_name' => 'Microsoft Xbox Series X',
            ],
            'microsoft-xbox-series-x-1tb',
        ];
        yield 'ebay-cpus' => [
            'ebay-cpus',
            'AMD Ryzen 7 7800X3D Processor',
            [],
            'amd-ryzen-7-7800x3d',
        ];
        yield 'ebay-graphics-cards' => [
            'ebay-graphics-cards',
            'AMD Radeon RX 7900 XT 20GB',
            [],
            'amd-radeon-rx-7900-xt-20gb',
        ];
        yield 'ebay-headphones' => [
            'ebay-headphones',
            'Sony WH-1000XM4 Wireless Headphones',
            [
                'Brand' => 'Sony',
                'Model' => 'WH-1000XM4',
            ],
            'sony-wh1000xm4',
        ];
        yield 'ebay-honda-msx125' => [
            'ebay-honda-msx125',
            'Honda MSX125 Grom 125cc',
            [
                'Make' => 'Honda',
                'Engine Size' => '125cc',
            ],
            'honda-msx125-125cc',
        ];
        yield 'ebay-honda-nc750' => [
            'ebay-honda-nc750',
            'Honda NC750X Gear Lever',
            [
                'Brand' => 'Honda',
                'Model' => 'NC750X',
                'Fitment 1' => 'Honda NC750X',
                'Type' => 'Gear Lever',
                'Manufacturer Part Number' => '24700-MGS-D30',
            ],
            'honda-nc750x-24700mgsd30',
        ];
        yield 'ebay-industrial-automation' => [
            'ebay-industrial-automation',
            'Siemens SIMATIC S7-1500 CPU 6ES7 511-1AK02-0AB0',
            [],
            'siemens-6es7-511-1ak02-0ab0',
        ];
        yield 'ebay-lego' => [
            'ebay-lego',
            'LEGO Stranger Things The Upside Down 75810 Complete Set',
            [],
            'lego-set-75810',
        ];
        yield 'ebay-mini-cooper' => [
            'ebay-mini-cooper',
            'Mini Cooper S R56 1.6 2010',
            [
                'Manufacturer' => 'Mini',
                'Vehicle Model' => 'Cooper S',
            ],
            'mini-coopers-r56-1.6l-2010',
        ];
        yield 'ebay-motherboards' => [
            'ebay-motherboards',
            'MSI MAG B650 Tomahawk WiFi AM5 Motherboard',
            [],
            'msi-mag-b650-tomahawk-wifi',
        ];
        yield 'ebay-motors' => [
            'ebay-motors',
            'Volkswagen Golf GT TDI Estate',
            [
                'Manufacturer' => 'volgswagen',
                'Model' => 'Golf GT TDI',
                'Engine' => '1.9 TDI',
                'Fuel Type' => 'Diesel',
                'Transmission' => 'Manual',
                'Body Type' => 'Estate',
                'Date of 1st Registration' => '01032009',
            ],
            'vehicle-volkswagen-golfgt-1.9l-diesel-manual-estate-2009',
        ];
        yield 'ebay-pokemon' => [
            'ebay-pokemon',
            'Bulbasaur 166/165 Sv2a: Pokemon Card 151 Art Rare Holo (Japanese)',
            [
                'Franchise' => 'Pokemon TCG',
                'Set' => 'SV2a: Pokemon Card 151',
                'Card Number' => '166/165',
                'Language' => 'Japanese',
                'Condition' => 'Used',
            ],
            'pokemon-sv2apokemoncard151-166-165-holo_ja',
        ];
        yield 'ebay-retro-pc' => [
            'ebay-retro-pc',
            '3dfx Voodoo3 3000 16MB AGP graphics card',
            [],
            'retro-graphics-3dfx-voodoo3-3000-16mb-agp',
        ];
        yield 'ebay-samsung' => [
            'ebay-samsung',
            'Samsung Galaxy A55 5G 128GB Awesome Navy',
            [
                'Brand' => 'Samsung',
                'Model' => 'Galaxy A55 5G',
                'Storage Capacity' => '128 GB',
            ],
            'samsung-galaxy-a55-128gb-5g',
        ];
        yield 'ebay-tools' => [
            'ebay-tools',
            'Mafell A 12 Cordless 12V Impact Drill Driver 2 x Batteries Charger T-Max Case',
            [
                'Brand' => 'MAFELL',
                'Model' => '91D021',
                'MPN' => '91D021',
                'Voltage' => '12 V',
                'Motor Type' => 'Brushless',
            ],
            'mafell-a12-12v-brushless-kit',
        ];
        yield 'ebay-watches' => [
            'ebay-watches',
            'Seiko Titanium Flightmaster GMT AGS Kinetic 5M45-6A50, 90s Classic',
            [],
            'seiko-5m456a50-kinetic-watchonly',
        ];
        yield 'motomine' => [
            'motomine',
            'Lot#66848 2015 YAMAHA YZF-R125 ABS -SPARES/REPAIR',
            [
                'Manufacturer' => 'YAMAHA',
                'Model' => 'YZF R125 ABS',
                'Capacity (cc)' => '124',
            ],
            'motomine-yamaha-yzfr125-125cc',
        ];
    }

    public static function guardCases(): iterable
    {
        yield 'conflicting mac storage' => [
            'ebay-apple',
            'MacBook Air M2 A2681 8GB RAM 256GB SSD',
            [
                'SSD Capacity' => '512 GB',
            ],
            'unknown',
        ];
        yield 'small iPod nano storage' => [
            'ebay-ipod',
            'Apple iPod nano 7th Generation 16GB Blue',
            [],
            'apple-ipod-nano-7th-gen-16gb',
        ];
        yield 'iPod nano 8GB is storage not RAM' => [
            'ebay-ipod',
            'Apple iPod Nano 4th Gen 8GB Purple',
            [],
            'apple-ipod-nano-4th-gen-8gb',
        ];
        yield 'iPod shuffle' => [
            'ebay-ipod',
            'Apple iPod shuffle 4th Generation 2GB Blue',
            [],
            'apple-ipod-shuffle-4th-gen-2gb',
        ];
        yield 'iPod video shares classic key' => [
            'ebay-ipod',
            'Apple iPod Video 30GB 5th Gen Black',
            [],
            'apple-ipod-classic-5th-gen-30gb',
        ];
        yield 'iPod from storage attribute' => [
            'ebay-ipod',
            'Apple iPod touch 7th Generation Space Grey',
            ['Storage Capacity' => '128 GB'],
            'apple-ipod-touch-7th-gen-128gb',
        ];
        yield 'iPod accessory' => [
            'ebay-ipod',
            'iFlash Quad SD adapter for iPod Classic 256GB',
            [],
            'unknown',
        ];
        yield 'iPod battery' => [
            'ebay-ipod',
            'Battery 240mAh Type 616-0405 616-0407 for Apple iPod Nano 4th 8GB',
            [],
            'unknown',
        ];
        yield 'iPod mixed storage' => [
            'ebay-ipod',
            'Apple iPod nano 3rd Gen 4GB 8GB',
            [],
            'unknown',
        ];
        yield 'iPod generation from model number' => [
            'ebay-ipod',
            'Apple iPod Nano A1446 16GB Space Grey',
            [],
            'apple-ipod-nano-7th-gen-16gb',
        ];
        yield 'iPod generation from item specifics' => [
            'ebay-ipod',
            'Apple iPod Touch 32GB Blue',
            ['Generation' => '5th Generation'],
            'apple-ipod-touch-5th-gen-32gb',
        ];
        yield 'iPod without a generation' => [
            'ebay-ipod',
            'Apple iPod Nano 8GB Green',
            [],
            'unknown',
        ];
        yield 'iPod single-size generation fills the capacity' => [
            'ebay-ipod',
            'Apple iPod Shuffle 4th Generation Pink',
            [],
            'apple-ipod-shuffle-4th-gen-2gb',
        ];
        yield 'iPod capacity corrects a mislabelled generation' => [
            'ebay-ipod',
            'Apple iPod Classic 120 GB Black 7th Generation 2008',
            [],
            'apple-ipod-classic-6th-gen-120gb',
        ];
        yield 'iPod flash mod' => [
            'ebay-ipod',
            'Apple iPod Video 5th Gen 256GB iFlash Quad New Battery',
            [],
            'apple-ipod-classic-mod-256gb',
        ];
        yield 'iPod non-stock capacity is a mod' => [
            'ebay-ipod',
            'Apple iPod Classic 6th Gen 128GB Silver Refurbished',
            [],
            'apple-ipod-classic-mod-128gb',
        ];
        yield 'iPod mini has no generation' => [
            'ebay-ipod',
            'Apple iPod mini 2nd Gen 6GB Green',
            [],
            'apple-ipod-mini-6gb',
        ];
        yield 'iPod original click wheel sells as classic' => [
            'ebay-ipod',
            'Apple iPod 3rd Generation A1040 20GB White',
            [],
            'apple-ipod-classic-3rd-gen-20gb',
        ];
        yield 'iPod shuffle in megabytes' => [
            'ebay-ipod',
            'Apple iPod Shuffle 1st Generation 512MB White',
            [],
            'apple-ipod-shuffle-1st-gen-512mb',
        ];
        yield 'iPod multi-unit lot' => [
            'ebay-ipod',
            '3 X iPod Nano 4th Gen 8GB - Need New Battery',
            [],
            'unknown',
        ];
        yield 'iPod in the Apple source' => [
            'ebay-apple',
            'Apple iPod Nano 6th Gen 16GB Graphite',
            [],
            'apple-ipod-nano-6th-gen-16gb',
        ];
        yield 'RC named model ignores the scale' => [
            'ebay-rc',
            'HPI Racing Rush Evo 1:10 2WD Nitro Off-Road RTR Truck',
            [],
            'rc-car-hpi-rushevo',
        ];
        yield 'RC aircraft engine is out of scope' => [
            'ebay-rc',
            'OS Max 46 2-Stroke Nitro/Glow RC Aircraft Engine with Muffler',
            [],
            'unknown',
        ];
        yield 'RC aftermarket part for another brand' => [
            'ebay-rc',
            'Hot Racing Aluminium Steering Knuckles For Losi DBXL',
            [],
            'unknown',
        ];
        yield 'RC car that comes with its radio' => [
            'ebay-rc',
            'Kyosho Fazer MK2 1/10 RTR with radio',
            [],
            'rc-car-kyosho-fazermk2',
        ];
        yield 'RC category from the Trading API' => [
            'ebay-rc',
            'Schumacher Cougar KF2 roller',
            ['Trading PrimaryCategoryName' => 'Toys & Games:Radio Control & RC Toys:RC Model Vehicles & Kits'],
            'rc-car-schumacher-kf2',
        ];
        yield 'Headset is not a console from its description' => [
            'ebay-consoles',
            'Logitech Astro A10 Wired Over-The-Head Stereo Gaming Headset Black Binaural Circ',
            [
                'Brand' => 'Logitech',
                'Trading Description' => 'Explosive audio to provide comfort. Compatible Products: PS4 PS5 Xbox Series',
            ],
            'unknown-accessory',
        ];
        yield 'Console bundled with a headset stays a console' => [
            'ebay-consoles',
            'Microsoft Xbox Series S 512GB Console With Headset',
            [],
            'microsoft-xbox-series-s-512gb',
        ];
        yield 'Catalogue Series X title without storage is 1TB' => [
            'ebay-consoles',
            'Microsoft Xbox Series X Home Console Black with USB-A Connectivity',
            ['Type' => 'Home Console', 'Model' => 'Microsoft Xbox Series X'],
            'microsoft-xbox-series-x-1tb',
        ];
        yield 'Series X|S platform name does not hide a Series S' => [
            'ebay-consoles',
            'Microsoft Xbox Series S Home Console White/Black USB-A Microsoft Xbox Series X/S',
            ['Type' => 'Home Console', 'Platform' => 'Microsoft Xbox Series X|S'],
            'microsoft-xbox-series-s-512gb',
        ];
        yield 'Carbon Black Series S is 1TB' => [
            'ebay-consoles',
            'Microsoft Xbox Series S Carbon Black Console',
            [],
            'microsoft-xbox-series-s-1tb',
        ];
        yield 'Terabyte spelled out' => [
            'ebay-consoles',
            'Microsoft Xbox Series X Home Console Black 1 terabyte HDMI USB-A',
            [],
            'microsoft-xbox-series-x-1tb',
        ];
        yield 'One X without storage is 1TB' => [
            'ebay-consoles',
            'Microsoft Xbox One X Home Console Black',
            [],
            'microsoft-xbox-one-x-1tb',
        ];
        yield 'One S without storage stays unknown' => [
            'ebay-consoles',
            'Microsoft Xbox One S White Home Console with HDMI USB-A Connectivity',
            [],
            'unknown',
        ];
        yield 'Headphone jack is not a headset' => [
            'ebay-consoles',
            'Microsoft Xbox Series S Home Console 512GB White USB-A Headphone Jack',
            [],
            'microsoft-xbox-series-s-512gb',
        ];
        yield 'Storage expansion card is not a console' => [
            'ebay-consoles',
            'Seagate Storage Expansion Card 1TB for Xbox Series X|S',
            [],
            'accessory-xbox-series-storage',
        ];
        yield 'Series X controller is not a console' => [
            'ebay-consoles',
            'Microsoft Xbox Series X Wireless Controller Carbon Black',
            [],
            'accessory-xbox-series-controller-official',
        ];
        yield 'Switch console with Joy-Con controllers stays a console' => [
            'ebay-consoles',
            'Nintendo Switch Console w/ Dock & Joy-Con Controllers, Mario Kart 8 Deluxe Box',
            [],
            'nintendo-switch-base',
        ];
        yield 'Joy-Con controller alone is not a console' => [
            'ebay-consoles',
            'Nintendo Switch Joy-Con Controller Pair Neon Red Blue',
            [],
            'accessory-switch-joy-con-pair-official',
        ];
        yield 'Dust sleeve for a Series X is not a console' => [
            'ebay-consoles',
            'Oxford cloth Dust Cover Protective Sleeve for Xbox Series X Game Console Black A',
            [],
            'unknown',
        ];
        yield 'Cooling fan for a Series X is not a console' => [
            'ebay-consoles',
            '5V/2A USB Vertical Dual Silent Fan External Cooler For Xbox Series X Console C',
            [],
            'accessory-xbox-series-cooling',
        ];
        yield 'Wall bracket for a Series X is not a console' => [
            'ebay-consoles',
            'VIVO Steel Wall Mount Bracket Designed for Xbox Series X Gaming Console, Horizon',
            [],
            'accessory-xbox-series-stand',
        ];
        yield 'Joy-Con for a Switch console is not a console' => [
            'ebay-consoles',
            'Wireless Joy-Con Controller for Nintendo Switch Console, Blue/Red',
            [],
            'accessory-switch-joy-con-third-party',
        ];
        yield 'Series X mini fridge is not a console' => [
            'ebay-consoles',
            'Microsoft Xbox Series X Home Console, Black, HDMI & USB-A style mini fridge',
            [],
            'unknown',
        ];
        yield 'Series X game naming the console is not a console' => [
            'ebay-consoles',
            'A Plague Tale: Requiem Xbox Series X Console Action-Adventure XSX Video Game',
            [],
            'unknown',
        ];
        yield 'Series X bundled with video games stays a console' => [
            'ebay-consoles',
            'Xbox Series X - Console, Video Games, and A Controller',
            [],
            'microsoft-xbox-series-x-1tb',
        ];
        yield 'Series X bundle with a wired controller stays a console' => [
            'ebay-consoles',
            'Xbox Series X Bundle + Turtle Beach Wired Controller & Astro A10 Headset',
            [],
            'microsoft-xbox-series-x-1tb',
        ];
        yield 'PS3 with a wired controller stays a console' => [
            'ebay-consoles',
            'Sony Playstation 3 PS3 Slim 120GB CECH-2003A & Wired Controller (U)',
            [],
            'sony-playstation-3',
        ];
        yield 'Wired controller is not an Xbox 360' => [
            'ebay-consoles',
            'Microsoft Xbox 360 Wired Controller Black',
            [],
            'accessory-xbox-360-controller-official',
        ];
        yield 'PS3 games bundle is not a console' => [
            'ebay-consoles',
            'Bundle of 10 Games for the Sony PS3 PlayStation 3 FREE POSTAGE',
            [],
            'unknown',
        ];
        yield 'PS3 game bundle is not a console' => [
            'ebay-consoles',
            'Sony PS3 PlayStation 3 Game Bundle x5 Call of Duty',
            [],
            'unknown',
        ];
        yield 'Switch game bundle is not a console' => [
            'ebay-consoles',
            'Maximum Games Doctor Who: Duo Bundle Nintendo Switch PAL (2022) PEGI 12',
            [],
            'unknown',
        ];
        yield 'PS3 game titles bundle is not a console' => [
            'ebay-consoles',
            'Ratchet & Clank PS3 PAL Bundle: A Crack in Time + Tools of Destruction Manual',
            [],
            'unknown',
        ];
        yield 'microSD card for Switch 2 is not a console' => [
            'ebay-consoles',
            'SanDisk 256GB microSD Express Card for Nintendo Switch 2',
            [],
            'unknown',
        ];
        yield 'PS3 hard drive is not a console' => [
            'ebay-consoles',
            '320GB Hard Drive for Sony PS3 Slim',
            [],
            'accessory-ps3-storage',
        ];
        yield 'PS3 with a legacy drive size is a console' => [
            'ebay-consoles',
            'Sony PlayStation 3 PS3 Slim 160GB Black PAL HDMI Wi-Fi CECH-2503A FIFA 12',
            [],
            'sony-playstation-3',
        ];
        yield 'Switch Lite battery is not a console' => [
            'ebay-consoles',
            'BATTERIE 3200mAh HDH-0003 HDH-A-BPHAT-C0 For Nintendo Switch Lite, HDH-001',
            ['Platform' => ['Nintendo Switch LiTE'], 'Compatible Model' => ['Nintendo Switch']],
            'accessory-switch-battery',
        ];
        yield 'Listing outside the consoles category is not a console' => [
            'ebay-consoles',
            '2 PACK Nintendo Switch OLED 2021 Genuine 100% Tempered Glass Screen Protector',
            ['Browse categoryId' => '171837'],
            'unknown',
        ];
        yield 'Listing in the consoles category still keys' => [
            'ebay-consoles',
            'Nintendo Switch Lite Turquoise',
            ['Browse categoryId' => '139971'],
            'nintendo-switch-lite',
        ];
        yield 'Game outside the consoles category still keys as a game' => [
            'ebay-consoles',
            'Nintendo Pokémon: Let\'s Go, Pikachu! Nintendo Switch',
            ['Game Name' => 'Pokémon: Let\'s Go, Pikachu!', 'Platform' => 'Nintendo Switch', 'Trading PrimaryCategoryID' => '139973'],
            'game-switch-pokemon-lets-go-pikachu',
        ];
        yield 'PlayVital accessory is not a PS Vita' => [
            'ebay-consoles',
            'PlayVital Back Cover for Nintendo Switch Console NS Joycon Chameleon Scarlet Red',
            [],
            'accessory-switch-cover',
        ];
        yield 'console wrap is not a console' => [
            'ebay-consoles',
            'Xbox Series X Call Of Duty Black Ops 6 Console Wrap',
            [],
            'accessory-xbox-series-skin',
        ];
        yield 'console skin is not a console' => [
            'ebay-consoles',
            'PS5 Slim Disc Edition Console Skin Decal Spider-Man',
            [],
            'accessory-ps5-skin',
        ];
        yield 'console with a skin is still a console' => [
            'ebay-consoles',
            'Microsoft Xbox Series S Home Console Spider-Man Skin + Controller Wi-Fi USB-A',
            [],
            'microsoft-xbox-series-s-512gb',
        ];
        yield 'console wearing a skin is still a console' => [
            'ebay-consoles',
            'Microsoft Xbox One S Home Console Grey/Black Camo Skin Wi-Fi USB-A Console Only 500GB',
            [],
            'microsoft-xbox-one-s-500gb',
        ];
        yield 'shrink wrapped console is still a console' => [
            'ebay-consoles',
            'Xbox Series X 1TB Console Brand New Shrink Wrap',
            [],
            'microsoft-xbox-series-x-1tb',
        ];
        yield 'official PS5 controller is a keyed accessory' => [
            'ebay-consoles',
            'Sony PS5 DualSense Wireless Controller – The Last of Us Limited Edition',
            [],
            'accessory-ps5-controller-official',
        ];
        yield 'third-party PS5 controller is priced apart from the official one' => [
            'ebay-consoles',
            'Gamrombo Wireless Controller for PS5/Pro/Slim/PC/Steam Deck Back #Ivory White',
            [],
            'accessory-ps5-controller-third-party',
        ];
        yield 'PS5 disc drive is a keyed accessory' => [
            'ebay-consoles',
            'Sony PS5 Slim Disc Drive Attachment CFI-ZDD1 Brand New',
            [],
            'accessory-ps5-disc-drive',
        ];
        yield 'Xbox One power supply is a keyed accessory' => [
            'ebay-consoles',
            'Official Microsoft Xbox One Power Supply Model A12-220P1A With UK Plug 1540',
            [],
            'accessory-xbox-one-power-official',
        ];
        yield 'Elite controller works across Xbox generations' => [
            'ebay-consoles',
            'Xbox One Elite Serier 2 Wireless Controller - Black',
            [],
            'accessory-xbox-controller-elite',
        ];
        yield 'several controllers are an accessory but not one to value' => [
            'ebay-consoles',
            '2x Untested Emerald Xbox 1 Power A Controllers',
            [],
            'unknown-accessory',
        ];
        yield 'console sold with a case is not a case' => [
            'ebay-consoles',
            'Galaxy Switch Lite with Protective Case and Carry Case',
            [],
            'unknown',
        ];
        yield 'console listed as a controller is not a controller' => [
            'ebay-consoles',
            'PS4 Console (500gb) 1 Controller, Controller(s) Holder',
            ['Type' => 'Controller Holder'],
            'unknown',
        ];
        yield 'console with a faulty port is not a part' => [
            'ebay-consoles',
            'PS5 825GB Disc Console FAULTY HDMI PORT',
            [],
            'unknown',
        ];
        yield 'Elite steering wheel is a wheel' => [
            'ebay-consoles',
            'Fanatec McLaren GT3 V2 CSL Elite Steering Wheel QR2 Lite Xbox PS PC (not used)',
            [],
            'accessory-xbox-wheel',
        ];
        yield 'a plainly named controller is the official one' => [
            'ebay-consoles',
            'Xbox One Controller Grey Green',
            [],
            'accessory-xbox-one-controller-official',
        ];
        yield 'PS VR2 is not a headset' => [
            'ebay-consoles',
            'Sony Playstation VR2 Headset White Sony Playstation PS5',
            [],
            'accessory-ps5-vr',
        ];
        yield 'a part of a controller is a part' => [
            'ebay-consoles',
            'Flex Cable For L R Controller Sony PS5 PlayStation',
            [],
            'accessory-ps5-repair-part',
        ];
        yield 'a case for an Elite controller is a case' => [
            'ebay-consoles',
            'Microsoft Xbox Elite Series 2 controller carrying case. Case ONLY',
            [],
            'accessory-xbox-case',
        ];
        yield 'pedals alone are not a wheel' => [
            'ebay-consoles',
            'Pedals ONLY Hori RWA Racing Wheel Apex Wireless Steering Wheel PS4',
            [],
            'accessory-ps4-pedals',
        ];
        yield 'a VR game is not a VR headset' => [
            'ebay-consoles',
            "Star Wars Tales From The Galaxy's Edge - (PS5 Enhanced) PS VR2",
            [],
            'unknown',
        ];
        yield 'a controller battery is a battery' => [
            'ebay-consoles',
            'Sony PlayStation PS5 DualSense Controller 4000mAh Battery Replacement Kit',
            [],
            'accessory-ps5-repair-part',
        ];
        yield 'a controller holder is a stand' => [
            'ebay-consoles',
            'Ps5 Ps4 Controller Holder',
            [],
            'accessory-ps5-stand',
        ];
        yield 'earpads are a part' => [
            'ebay-consoles',
            'Earpads ONLY Astro A10 Gen 2 gaming headset 3.5mm wired PS5',
            [],
            'accessory-ps5-repair-part',
        ];
        yield 'PS Vita' => [
            'ebay-consoles',
            'Sony PS Vita PCH-2000 Aqua Blue Handheld Console',
            [],
            'sony-playstation-vita',
        ];
        yield 'Switch 2 camera is not a console' => [
            'ebay-consoles',
            'Nintendo Switch 2 Camera/Web-Cam USB with Stand, Privacy Shutter, Black',
            [],
            'accessory-switch-2-camera',
        ];
        yield 'Switch 2 power case is not a console' => [
            'ebay-consoles',
            'Venom Nintendo Switch 2 Power Case with 10,000mAh Power Bank and Game Storage',
            [],
            'unknown',
        ];
        yield 'Switch Lite with a new battery fitted stays a console' => [
            'ebay-consoles',
            'Nintendo Switch Lite Console, New Battery Fitted, Coral',
            [],
            'nintendo-switch-lite',
        ];
        yield 'Switch V2 with an improved battery stays a console' => [
            'ebay-consoles',
            'Nintendo Switch Console 32gb V2 Improved Battery Complete Boxed Red & Blue',
            [],
            'nintendo-switch-base',
        ];
        yield 'Xbox listed with a battery pack stays a console' => [
            'ebay-consoles',
            'XBOX Series S 512GB ** Battery Pack ** 100% Fully Working ** Free Next Day',
            [],
            'microsoft-xbox-series-s-512gb',
        ];
        yield 'Toggle switch is not a Nintendo Switch' => [
            'ebay-consoles',
            'On OFF On 3 Position Momentary Toggle Switch 6 Pin 12mm 15A 250VAC',
            ['Brand' => 'Unbranded'],
            'unknown',
        ];
        yield 'Car charger is not a Switch Lite' => [
            'ebay-consoles',
            'PowerA Nintendo Switch & Switch Lite Car Charger Black USB High-Speed Power A UK',
            ['Type' => 'Car Charger', 'Platform' => 'Nintendo Switch'],
            'accessory-switch-charger-third-party',
        ];
        yield 'Power adapter is not an Xbox 360' => [
            'ebay-consoles',
            'Microsoft Xbox 360 Slim Console CPA09-011A 135W 10.83A AC Power Adapter UK Plug',
            [],
            'accessory-xbox-360-power-official',
        ];
        yield 'Console listed with a charger stays a console' => [
            'ebay-consoles',
            'Nintendo Switch Lite Coral Console and Charger - Free and Fast P&P',
            ['Brand' => 'Nintendo'],
            'nintendo-switch-lite',
        ];
        yield 'Pro in the description is not a PS5 Pro' => [
            'ebay-consoles',
            'Sony PlayStation 5 PS5 Disc Edition 825GB White Console',
            ['Trading Description' => 'Professionally cleaned, product works'],
            'sony-playstation-5-disc-825gb',
        ];
        yield 'Pro controller is not a PS5 Pro' => [
            'ebay-consoles',
            'Sony PS5 Disc Edition 825GB + DualSense + Custom Pro Controller',
            [],
            'sony-playstation-5-disc-825gb',
        ];
        yield 'PS5 Pro' => [
            'ebay-consoles',
            'Sony PlayStation 5 Pro Console 2TB',
            [],
            'sony-playstation-5-pro-2tb',
        ];
        yield 'Game from Game Name' => [
            'ebay-consoles',
            'Uncharted 4: A Thief\'s End PS4 / PlayStation 4 Game, VGC, Free Postage',
            ['Game Name' => 'Uncharted 4: A Thief\'s End', 'Platform' => 'Sony PlayStation 4'],
            'game-ps4-uncharted-4-a-thiefs-end',
        ];
        yield 'Game name with mis-encoded accent' => [
            'ebay-consoles',
            'Nintendo Pokémon: Let\'s Go, Pikachu! Nintendo Switch',
            ['Game Name' => 'PokÃ©mon: Let\'s Go, Pikachu!', 'Platform' => 'Nintendo Switch'],
            'game-switch-pokemon-lets-go-pikachu',
        ];
        yield 'Game title decides the platform' => [
            'ebay-consoles',
            'Cloudy With A Chance Of Meatballs Nintendo Wii game',
            ['Game Name' => 'Cloudy with a Chance of Meatballs', 'Platform' => 'Nintendo GameCube'],
            'game-wii-cloudy-with-a-chance-of-meatballs',
        ];
        yield 'Game release year is not Switch 2' => [
            'ebay-consoles',
            'Nintendo Bayonetta 3 (Nintendo Switch, 2022) PAL',
            ['Game Name' => 'Bayonetta 3', 'Platform' => 'Nintendo Switch'],
            'game-switch-bayonetta-3',
        ];
        yield 'Game Switch 2 edition' => [
            'ebay-consoles',
            'Cyberpunk 2077 Ultimate Edition (Nintendo Switch 2) Game Cartridge',
            ['Game Name' => 'Cyberpunk 2077', 'Platform' => 'Nintendo Switch 2'],
            'game-switch-2-cyberpunk-2077-ultimate',
        ];
        yield 'Game edition in the name or the title is one key' => [
            'ebay-consoles',
            'Harry Potter Quidditch Champions Deluxe Edition Nintendo Switch',
            ['Game Name' => 'Harry Potter Quidditch Champions Deluxe Edition'],
            'game-switch-harry-potter-quidditch-champions-deluxe',
        ];
        yield 'Game budget reprint' => [
            'ebay-consoles',
            'PlayStation2 : Gran Turismo 3: A -Spec Platinum (PS2)',
            ['Game Name' => 'Gran Turismo 3 A-Spec'],
            'game-ps2-gran-turismo-3-a-spec-budget',
        ];
        yield 'Game cartridge only' => [
            'ebay-consoles',
            'Asterix Nintendo NES Game PAL A UKV Cartridge Loose With Black Sleeve',
            ['Game Name' => 'Asterix', 'Platform' => 'Nintendo NES'],
            'game-nes-asterix-loose',
        ];
        yield 'Game Japanese import' => [
            'ebay-consoles',
            'Call of Duty 4 Modern Warfare Microsoft Xbox 360 Japanese Japan Import NTSC-J',
            ['Game Name' => 'Call of Duty 4: Modern Warfare'],
            'game-xbox-360-call-of-duty-4-modern-warfare-jp',
        ];
        yield 'Game platform typed into the name' => [
            'ebay-consoles',
            'PS2 ROBOCOP - A New crisis Japan PlayStation 2 Tested',
            ['Game Name' => 'PS2 ROBOCOP - A New crisis Japan PlayStation 2'],
            'game-ps2-robocop-a-new-crisis-jp',
        ];
        yield 'Game series name only' => [
            'ebay-consoles',
            'Pokemon Legends Z-A Nintendo Switch',
            ['Game Name' => 'Pokémon', 'Platform' => 'Nintendo Switch'],
            'unknown',
        ];
        yield 'Game name missing from title' => [
            'ebay-consoles',
            'Rockstar L.A. Noire Xbox 360 Video Game Disc 2 of 3',
            ['Game Name' => 'Puzzle-Platformer', 'Platform' => 'PSP'],
            'unknown',
        ];
        yield 'Game bundle' => [
            'ebay-consoles',
            'Dynasty Warriors 6, 7 & 8, Plus 6 Empires, 4x Game Bundle (Microsoft Xbox 360)',
            ['Game Name' => 'Dynasty Warriors 8'],
            'unknown',
        ];
        yield 'Game graded' => [
            'ebay-consoles',
            '1st Print Fallout 3 - Xbox 360 - WATA 9.8 A+ - GRADED SEALED',
            ['Game Name' => 'Fallout 3'],
            'unknown',
        ];
        yield 'Game Name on a console stays hardware' => [
            'ebay-consoles',
            'Microsoft XBox One 500GB HDD Video Game Console System Black Original.',
            ['Game Name' => 'Xbox One 500GB Console - Black', 'Platform' => 'Microsoft Xbox One'],
            'unknown',
        ];
        yield 'RC part keeps apart from the kit' => [
            'ebay-rc',
            'Xray T4 front suspension arms graphite',
            ['Browse categoryPath' => 'Toys & Games|Radio Control & RC Toys|RC Model Vehicle Parts & Accs|Other RC Parts & Accs'],
            'rc-part-xray-t4',
        ];
        yield 'RC electronics' => [
            'ebay-rc',
            'Savox SB-2290SG Brushless Servo',
            ['Browse categoryPath' => 'Toys & Games|Radio Control & RC Toys|RC Model Vehicle Parts & Accs|Other RC Parts & Accs'],
            'rc-servo-savox-sb2290sg',
        ];
        yield 'RC car listed under parts' => [
            'ebay-rc',
            'Schumacher Fusion R12 Shelf Queen 2 Speed 1/10 Nitro RC Car Boxed Unused Rare',
            ['Browse categoryPath' => 'Toys & Games|Radio Control & RC Toys|RC Model Vehicle Parts & Accs|Other RC Parts & Accs'],
            'rc-car-schumacher-r12',
        ];
        yield 'RC faulty stays identified' => [
            'ebay-rc',
            'Schumacher Cat L1 Evo 1/10 4WD Buggy Kit - not working spares',
            ['Browse categoryId' => '182183'],
            'rc-car-schumacher-catl1',
        ];
        yield 'RC named vintage model' => [
            'ebay-rc',
            'Tamiya Hornet re-release 58336 boxed',
            [],
            'rc-car-tamiya-58336',
        ];
        yield 'RC Losi version number' => [
            'ebay-rc',
            'TLR Losi 22.1 bodyshell 1/10 used',
            ['Browse categoryPath' => 'Toys & Games|Radio Control & RC Toys|RC Model Vehicle Parts & Accs|Other RC Parts & Accs'],
            'rc-part-losi-221',
        ];
        yield 'RC race car from unlisted brand' => [
            'ebay-rc',
            'PR Racing S1 V3 1/10 4wd buggy',
            [],
            'unknown-review',
        ];
        yield 'RC Team Durango' => [
            'ebay-rc',
            'Team Durango DEX410 1/10 4wd buggy',
            [],
            'rc-car-teamdurango-dex410',
        ];
        yield 'RC known brand without a model' => [
            'ebay-rc',
            'Kyosho Peugeot 206 WRC PureTen EP Alpha 4WD 1/10 RC Car',
            [],
            'unknown-review',
        ];
        yield 'RC toy' => [
            'ebay-rc',
            'RC Car 1:16 Off Road Monster Truck 2.4GHz Kids Toy Gift for Boys',
            [],
            'unknown',
        ];
        yield 'RC toy brand' => [
            'ebay-rc',
            'DEERC 9300 RC Car 40km/h',
            [],
            'unknown',
        ];
        // Faulty listings keep their identity; comps eligibility values them against faulty sales only.
        yield 'repair-only Apple' => [
            'ebay-apple',
            'MacBook Air M2 A2681 8GB RAM 256GB SSD Parts Or Repair',
            [],
            'apple-macbook-air-a2681-136in-m2-8gb-256gb',
        ];
        yield 'faulty iPhone for parts' => [
            'ebay-apple',
            'Apple iPhone 13 128GB Midnight Faulty For Parts',
            [],
            'apple-iphone-13-128gb',
        ];
        yield 'locked iPhone for parts' => [
            'ebay-apple',
            'Apple iPhone 13 128GB iCloud locked for parts',
            [],
            'unknown',
        ];
        yield 'headphone packaging' => [
            'ebay-headphones',
            'Sony WH-1000XM4 box only',
            [
                'Brand' => 'Sony',
                'Model' => 'WH-1000XM4',
            ],
            'unknown',
        ];
        yield 'retro cards require a bus' => [
            'ebay-retro-pc',
            '3dfx Voodoo3 3000 16MB graphics card',
            [],
            'unknown',
        ];
        yield 'retro card with missing variant' => [
            'ebay-retro-pc',
            '3dfx Voodoo3 16MB AGP graphics card',
            [],
            'unknown',
        ];
        yield 'retro bus conflict' => [
            'ebay-retro-pc',
            '3dfx Voodoo3 3000 16MB AGP graphics card',
            [
                'Bus Type' => 'PCI',
            ],
            'unknown',
        ];
        yield 'retro sound card' => [
            'ebay-retro-pc',
            'Creative Sound Blaster AWE64 CT4500 ISA sound card',
            [],
            'retro-sound-creative-ct4500',
        ];
        yield 'retro processor' => [
            'ebay-retro-pc',
            'Intel 486DX2 66MHz CPU',
            [],
            'retro-cpu-intel-486dx2-66mhz',
        ];
        yield 'retro k6 plus' => [
            'ebay-retro-pc',
            'AMD K6-2+ 500MHz CPU',
            [],
            'retro-cpu-amd-k6-2-plus-500mhz',
        ];
        yield 'retro modern hardware' => [
            'ebay-retro-pc',
            'NVIDIA RTX 4060 Ti 16GB',
            [],
            'unknown',
        ];
    }
}
