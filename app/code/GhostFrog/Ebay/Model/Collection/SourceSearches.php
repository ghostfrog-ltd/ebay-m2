<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Collection;

final class SourceSearches
{
    // Search seeds from the Python retrieve adapters, keyed by sources.domain.
    public const DEFINITIONS = [
        'motomine' => [
            'seller' => 'motomine',
            'page_limit' => 4,
            'listing_limit' => 250,
            'queries' => ['spares repair'],
        ],
        'ebay-apple' => [
            'categories' => [9355, 171485, 111422, 179, 15032],
            'queries' => ['apple'],
            'category_queries' => [
                111422 => [
                    'apple macbook',
                    'MacBook Pro M1 Max 64GB',
                    'MacBook Pro M2 Max 96GB',
                    'MacBook Pro M3 Max 4TB',
                    'MacBook Pro M4 Max 128GB',
                    'MacBook Pro 64GB',
                    'MacBook Pro 96GB',
                    'MacBook Pro 128GB',
                    'MacBook Pro 2TB',
                    'MacBook Pro 4TB',
                    'MacBook Pro 8TB',
                ],
                179 => [
                    'apple desktop',
                    'Mac Studio M1 Ultra',
                    'Mac Studio M2 Ultra',
                    'Mac Studio M3 Ultra',
                    'Mac Studio Max',
                    'Mac Studio Ultra',
                    'Mac Pro M2 Ultra',
                    'Mac Pro Apple Silicon',
                ],
            ],
        ],
        'ebay-ipod' => [
            // iPods & MP3 Players. Model terms keep generic MP3 players out of the results.
            'categories' => [73839],
            'queries' => [
                'ipod classic',
                'ipod video',
                'ipod nano',
                'ipod touch',
                'ipod shuffle',
                'ipod mini',
            ],
        ],
        'ebay-rc' => [
            // RC cars and kits (182183) and RC vehicle parts (182187). The toy-grade
            // "Remote-Controlled Toys" category (84912) is deliberately left out.
            'categories' => [182183, 182187],
            // A Buy It Now floor drops pocket-money cars. Auctions are left unfiltered
            // because good kits often start at 99p.
            'filters' => ['bin' => 'price:[30..],priceCurrency:GBP'],
            'category_queries' => [
                182183 => [
                    '(schumacher, xray, "team associated", losi, tlr, kyosho, yokomo, mugen)',
                    '(tekno, infinity, awesomatix, serpent, trf, tamiya)',
                    '(nitro, "1/8 buggy", "touring car", "pan car", "1/10 buggy", vintage)',
                ],
                182187 => [
                    '(sanwa, futaba, hobbywing, lrp, tekin, orion, savox)',
                    '(nitro, novarossi, picco, reds, sirio, "os engine")',
                    '(schumacher, xray, "team associated", losi, tlr, kyosho, yokomo, mugen)',
                    '(tekno, infinity, awesomatix, serpent, trf)',
                ],
            ],
        ],
        'ebay-consoles' => [
            'categories' => [139971, 54968, 139973],
            'queries' => ['a'],
        ],
        'ebay-retro-pc' => [
            'categories' => [
                11189,
                27386,
                44980,
                51197,
                164,
                170083,
                165,
            ],
            'queries' => ['a'],
        ],
        'ebay-actioncams' => [
            'categories' => [11724, 179697],
            'queries' => ['a'],
        ],
        'ebay-watches' => [
            'categories' => [31387],
            'queries' => ['a'],
        ],
        'ebay-tools' => [
            'categories' => [3247],
            'queries' => ['a'],
        ],
        'ebay-motors' => [
            'categories' => [9801, 179981, '9801x'],
            'queries' => ['a'],
        ],
        'ebay-lego' => [
            'categories' => [19006, 19001, 16722, 313],
            'queries' => ['a'],
        ],
        'ebay-pokemon' => [
            'categories' => [2611, 183454, 183454, 183448],
            'queries' => ['a'],
        ],
        'ebay-samsung' => [
            'categories' => [9355],
            'queries' => ['a'],
        ],
        'ebay-headphones' => [
            'categories' => [112529],
            'queries' => ['a'],
        ],
        'ebay-honda-nc750' => [
            'categories' => [179753],
            'queries' => ['a'],
        ],
        'ebay-honda-msx125' => [
            'categories' => [179753],
            'queries' => ['honda msx 125 grom'],
        ],
        'ebay-mini-cooper' => [
            'categories' => [9801, 6030],
            'queries' => ['mini cooper'],
        ],
        'ebay-graphics-cards' => [
            'categories' => [27386],
            'queries' => [
                'rtx 4070',
                'rtx 4070 ti',
                'rtx 4070 ti super',
                'rtx 4080',
                'rtx 4090',
                'rtx 3080',
                'rx 7800 xt',
                'rx 7900 xt',
                'rx 7900 xtx',
                'intel arc b580',
                'quadro rtx 4000',
                'quadro rtx 5000',
                'quadro rtx 6000',
                'quadro rtx 8000',
                'rtx a2000',
                'rtx a4000',
                'rtx a4500',
                'rtx a5000',
                'rtx a6000',
                'rtx 4000 ada',
                'rtx 4500 ada',
                'rtx 5000 ada',
                'rtx 6000 ada',
                'radeon pro w5700',
                'radeon pro w6800',
                'radeon pro w7500',
                'radeon pro w7600',
                'radeon pro w7800',
                'radeon pro w7900',
            ],
        ],
        'ebay-cpus' => [
            'categories' => [164],
            'queries' => [
                'ryzen 5 7600x',
                'ryzen 7 7700x',
                'ryzen 7 7800x3d',
                'ryzen 9 7900x',
                'ryzen 9 7950x',
                'intel i5 14600k',
                'intel i7 14700k',
                'intel i7 14700kf',
                'intel i9 14900k',
                'intel i9 14900kf',
            ],
        ],
        'ebay-motherboards' => [
            'categories' => [null],
            'queries' => [
                'b650 motherboard',
                'b650e motherboard',
                'x670e motherboard',
                'z790 motherboard',
                'z890 motherboard',
                'b760 motherboard',
                'asus rog strix b650e',
                'msi b650 tomahawk',
                'gigabyte b650 aorus',
                'asrock b650 motherboard',
            ],
        ],
        'ebay-camera-lenses' => [
            'categories' => [3323],
            'queries' => [
                'Sony 70-200 GM II',
                'Sony FE 85mm 1.4 GM',
                'Canon RF 85mm F1.2 L',
                'Canon RF 70-200 F2.8 L',
                'Nikon Z 70-200 F2.8 S',
                'Fujifilm GF 110mm F2',
                'Fujifilm XF 50-140 F2.8',
                'Leica Summicron M 35mm',
                'Hasselblad XCD 80mm',
                'Sigma 85mm F1.4 DG DN Art',
                'Tamron 35-150 F2-2.8 Sony',
                'Tamron 70-180 F2.8 Sony',
            ],
        ],
        'ebay-industrial-automation' => [
            'categories' => [null],
            'queries' => [
                'Siemens SIMATIC PLC',
                'Siemens S7-1500 CPU',
                'Siemens HMI panel',
                'Allen Bradley PLC',
                'Allen Bradley PanelView',
                'Omron PLC CPU',
                'Mitsubishi servo drive',
                'Pilz safety controller',
                'Beckhoff PLC',
                'B&R industrial PC',
                'Control Techniques drive',
                'Schneider Electric PLC',
            ],
        ],
    ];
}
