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
