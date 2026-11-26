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
            'rc-associated-car-b6-4',
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
            'rc-hpi-car-rush-evo',
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
            'rc-kyosho-car-fazer-mk2',
        ];
        yield 'RC category from the Trading API' => [
            'ebay-rc',
            'Schumacher Cougar KF2 roller',
            ['Trading PrimaryCategoryName' => 'Toys & Games:Radio Control & RC Toys:RC Model Vehicles & Kits'],
            'rc-schumacher-car-cougar-kf2',
        ];
        yield 'Headset is not a console from its description' => [
            'ebay-consoles',
            'Logitech Astro A10 Wired Over-The-Head Stereo Gaming Headset Black Binaural Circ',
            [
                'Brand' => 'Logitech',
                'Trading Description' => 'Explosive audio to provide comfort. Compatible Products: PS4 PS5 Xbox Series',
            ],
            'unknown',
        ];
        yield 'Console bundled with a headset stays a console' => [
            'ebay-consoles',
            'Microsoft Xbox Series S 512GB Console With Headset',
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
            'unknown',
        ];
        yield 'Power adapter is not an Xbox 360' => [
            'ebay-consoles',
            'Microsoft Xbox 360 Slim Console CPA09-011A 135W 10.83A AC Power Adapter UK Plug',
            [],
            'unknown',
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
            'rc-xray-part-t4',
        ];
        yield 'RC electronics' => [
            'ebay-rc',
            'Savox SB-2290SG Brushless Servo',
            ['Browse categoryPath' => 'Toys & Games|Radio Control & RC Toys|RC Model Vehicle Parts & Accs|Other RC Parts & Accs'],
            'rc-savox-servo-sb-2290sg',
        ];
        yield 'RC car listed under parts' => [
            'ebay-rc',
            'Schumacher Fusion R12 Shelf Queen 2 Speed 1/10 Nitro RC Car Boxed Unused Rare',
            ['Browse categoryPath' => 'Toys & Games|Radio Control & RC Toys|RC Model Vehicle Parts & Accs|Other RC Parts & Accs'],
            'rc-schumacher-car-fusion-r12',
        ];
        yield 'RC faulty stays identified' => [
            'ebay-rc',
            'Schumacher Cat L1 Evo 1/10 4WD Buggy Kit - not working spares',
            ['Browse categoryId' => '182183'],
            'rc-schumacher-car-cat-l1',
        ];
        yield 'RC named vintage model' => [
            'ebay-rc',
            'Tamiya Hornet re-release 58336 boxed',
            [],
            'rc-tamiya-car-58336',
        ];
        yield 'RC Losi version number' => [
            'ebay-rc',
            'TLR Losi 22.1 bodyshell 1/10 used',
            ['Browse categoryPath' => 'Toys & Games|Radio Control & RC Toys|RC Model Vehicle Parts & Accs|Other RC Parts & Accs'],
            'rc-losi-part-22-1',
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
            'rc-durango-car-dex410',
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
        yield 'repair-only Apple' => [
            'ebay-apple',
            'MacBook Air M2 A2681 8GB RAM 256GB SSD Parts Or Repair',
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
