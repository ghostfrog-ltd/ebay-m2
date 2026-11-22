<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey\Rules;

final class Components extends Rule
{
    private static function clean($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            return trim(implode(' ', array_map(static fn($v) => self::stringify($v), array_values(array_filter(self::iterate($value), static fn($v) => ($v !== null))))));
        }
        return trim(self::stringify($value));
    }

    private static function text(array $attrs, string $title): string
    {
        $bits = [
            $title,
            self::clean(($attrs['Brand'] ?? null)),
            self::clean(($attrs['Model'] ?? null)),
            self::clean(($attrs['MPN'] ?? null)),
            self::clean(($attrs['Compatible Brand'] ?? null)),
            self::clean(($attrs['Compatible Model'] ?? null)),
            self::clean(($attrs['Socket Type'] ?? null)),
            self::clean(($attrs['Memory Type'] ?? null)),
            self::clean(($attrs['Form Factor'] ?? null)),
        ];
        $txt = mb_strtolower(implode(' ', array_values(array_filter($bits, static fn($bit) => $bit))));
        $txt = str_replace('ti-super', 'ti super', $txt);
        $txt = str_replace('ti/super', 'ti super', $txt);
        $txt = str_replace('super/ti', 'super ti', $txt);
        $txt = str_replace('wi-fi', 'wifi', $txt);
        return implode(' ', self::words($txt));
    }

    private static function slug(string $value): string
    {
        return implode('-', self::findAll('[a-z0-9]+', mb_strtolower($value)));
    }

    private static function hasAny(string $text, $terms): bool
    {
        return self::containsAny($text, $terms);
    }

    private const BOX_ONLY_TERMS = [
        'box only',
        'empty box',
        'retail box only',
        'packaging only',
        'no card included',
        'no cpu included',
        'no motherboard included',
        'processor not included',
    ];

    private const BUNDLE_TERMS = [
        'job lot',
        'joblot',
        'bundle',
        'collection',
        'lot of',
        'multiple',
        '2x ',
        '3x ',
        '4x ',
        'x2 ',
        'x3 ',
        'x4 ',
        'pair of',
    ];

    private const GPU_ACCESSORY_TERMS = [
        'cooler only',
        'heatsink only',
        'heat sink only',
        'fan only',
        'fans only',
        'replacement fan',
        'fan shroud',
        'backplate',
        'bracket',
        'gpu support',
        'riser',
        'riser cable',
        'water block',
        'waterblock',
        'mining rig',
        'sxm',
        'repair service',
    ];

    private const CPU_ACCESSORY_TERMS = [
        'cooler only',
        'stock cooler only',
        'heatsink only',
        'heat sink only',
        'fan only',
        'keyring',
        'delidded',
        'engineering sample',
        'qualification sample',
    ];

    private const MOTHERBOARD_ACCESSORY_TERMS = [
        'i/o shield only',
        'io shield only',
        'backplate only',
        'wifi antenna only',
        'antenna only',
        'bios chip',
        'socket replacement',
        'repair service',
    ];

    private const LENS_ACCESSORY_TERMS = [
        'cap only',
        'lens cap',
        'rear cap',
        'body cap',
        'hood only',
        'lens hood',
        'filter',
        'adapter',
        'mount adapter',
        'case only',
        'pouch only',
        'box only',
        'empty box',
        'tripod collar',
        'collar only',
        'repair service',
        'fungus',
        'haze',
        'scratched glass',
    ];

    private const INDUSTRIAL_ACCESSORY_TERMS = [
        'manual',
        'book',
        'catalogue',
        'cable',
        'lead',
        'connector',
        'enclosure',
        'cabinet',
        'control cabinet',
        'repair service',
        'service only',
        'training',
        'software only',
        'license only',
        'licence only',
        'generic switch',
        'network switch',
        'complete machinery',
        'machine only',
        'freight',
        'collection only',
    ];

    private static function extractVramGb(string $text): ?int
    {
        $matches = self::findAll('\\b(4|6|8|10|11|12|16|20|24|32|48)\\s*gb\\b', $text);
        if (!$matches) {
            return null;
        }
        return max(array_map(static fn($m) => intval($m), $matches));
    }

    private const NVIDIA_DEFAULT_VRAM = [
        'rtx-2060' => 6,
        'rtx-2060-super' => 8,
        'rtx-2070' => 8,
        'rtx-2070-super' => 8,
        'rtx-2080' => 8,
        'rtx-2080-super' => 8,
        'rtx-2080-ti' => 11,
        'rtx-3050' => 8,
        'rtx-3060' => null,
        'rtx-3060-ti' => 8,
        'rtx-3070' => 8,
        'rtx-3070-ti' => 8,
        'rtx-3080' => null,
        'rtx-3080-ti' => 12,
        'rtx-3090' => 24,
        'rtx-3090-ti' => 24,
        'rtx-4060' => 8,
        'rtx-4060-ti' => null,
        'rtx-4070' => 12,
        'rtx-4070-super' => 12,
        'rtx-4070-ti' => 12,
        'rtx-4070-ti-super' => 16,
        'rtx-4080' => 16,
        'rtx-4080-super' => 16,
        'rtx-4090' => 24,
        'rtx-5060' => 8,
        'rtx-5060-ti' => null,
        'rtx-5070' => 12,
        'rtx-5070-ti' => 16,
        'rtx-5080' => 16,
        'rtx-5090' => 32,
        'gtx-1660' => 6,
        'gtx-1660-super' => 6,
        'gtx-1660-ti' => 6,
    ];

    private const AMD_DEFAULT_VRAM = [
        'rx-5600-xt' => 6,
        'rx-5700' => 8,
        'rx-5700-xt' => 8,
        'rx-6600' => 8,
        'rx-6600-xt' => 8,
        'rx-6650-xt' => 8,
        'rx-6700' => 10,
        'rx-6700-xt' => 12,
        'rx-6750-xt' => 12,
        'rx-6800' => 16,
        'rx-6800-xt' => 16,
        'rx-6900-xt' => 16,
        'rx-6950-xt' => 16,
        'rx-7600' => 8,
        'rx-7600-xt' => 16,
        'rx-7700-xt' => 12,
        'rx-7800-xt' => 16,
        'rx-7900-gre' => 16,
        'rx-7900-xt' => 20,
        'rx-7900-xtx' => 24,
        'rx-9060-xt' => 16,
        'rx-9070' => 16,
        'rx-9070-xt' => 16,
    ];

    private const INTEL_DEFAULT_VRAM = [
        'arc-a380' => 6,
        'arc-a580' => 8,
        'arc-a750' => 8,
        'arc-a770' => null,
        'arc-b580' => 12,
        'arc-b570' => 10,
    ];

    private const NVIDIA_QUADRO_DEFAULT_VRAM = [
        '4000' => 8,
        '5000' => 16,
        '6000' => 24,
        '8000' => 48,
    ];

    private const NVIDIA_RTX_A_DEFAULT_VRAM = [
        'a4000' => 16,
        'a4500' => 20,
        'a5000' => 24,
        'a6000' => 48,
    ];

    private const NVIDIA_RTX_ADA_DEFAULT_VRAM = [
        '4000' => 20,
        '4500' => 24,
        '5000' => 32,
        '6000' => 48,
    ];

    private const AMD_RADEON_PRO_DEFAULT_VRAM = [
        'w5700' => 8,
        'w6800' => 32,
        'w7500' => 8,
        'w7600' => 8,
        'w7800' => 32,
        'w7900' => 48,
    ];

    public static function graphicsCardModelKey(array $attrs, string $title = ''): ?string
    {
        $text = self::text($attrs, $title);
        if (!$text) {
            return self::UNKNOWN_KEY;
        }
        if (self::hasAny($text, self::BOX_ONLY_TERMS) || self::hasAny($text, self::GPU_ACCESSORY_TERMS)) {
            return self::UNKNOWN_KEY;
        }
        if (self::hasAny($text, self::BUNDLE_TERMS)) {
            return self::UNKNOWN_KEY;
        }
        if (self::search('\\b(laptop|notebook|mobile|mxm|sxm)\\b', $text)) {
            return self::UNKNOWN_KEY;
        }
        $quadro = self::search('\\bquadro\\s+rtx\\s*(4000|5000|6000|8000)\\b', $text);
        if ($quadro) {
            $number = $quadro->group(1);
            $vram = (self::extractVramGb($text) ?: (self::NVIDIA_QUADRO_DEFAULT_VRAM[$number] ?? null));
            if (!$vram) {
                return self::UNKNOWN_KEY;
            }
            return 'nvidia-quadro-rtx-' . $number . '-' . $vram . 'gb';
        }
        $rtxA = self::search('\\brtx\\s*-?\\s*(a2000|a4000|a4500|a5000|a6000)\\b', $text);
        if ($rtxA) {
            $model = $rtxA->group(1);
            $vram = (self::extractVramGb($text) ?: (self::NVIDIA_RTX_A_DEFAULT_VRAM[$model] ?? null));
            if (!$vram) {
                return self::UNKNOWN_KEY;
            }
            return 'nvidia-rtx-' . $model . '-' . $vram . 'gb';
        }
        $rtxAda = self::search('\\brtx\\s*(4000|4500|5000|6000)\\s+ada\\b', $text);
        if ($rtxAda) {
            $number = $rtxAda->group(1);
            $vram = (self::extractVramGb($text) ?: (self::NVIDIA_RTX_ADA_DEFAULT_VRAM[$number] ?? null));
            if (!$vram) {
                return self::UNKNOWN_KEY;
            }
            return 'nvidia-rtx-' . $number . '-ada-' . $vram . 'gb';
        }
        $radeonPro = self::search('\\bradeon\\s+pro\\s+(w5700|w6800|w7500|w7600|w7800|w7900)\\b', $text);
        if ($radeonPro) {
            $model = $radeonPro->group(1);
            $vram = (self::extractVramGb($text) ?: (self::AMD_RADEON_PRO_DEFAULT_VRAM[$model] ?? null));
            if (!$vram) {
                return self::UNKNOWN_KEY;
            }
            return 'amd-radeon-pro-' . $model . '-' . $vram . 'gb';
        }
        $nvidia = self::search('\\b(rtx|gtx)\\s*([1-5]\\d{3})\\s*(ti)?\\s*(super)?\\b', $text);
        if ($nvidia) {
            [$series, $number, $ti, $super] = $nvidia->groups();
            if (($series === 'gtx') && !self::any(['10', '16'], static fn(string $affix): bool => str_starts_with($number, $affix))) {
                return self::UNKNOWN_KEY;
            }
            $parts = [$series, $number];
            if ($ti) {
                $parts[] = 'ti';
            }
            if ($super) {
                $parts[] = 'super';
            }
            $model = implode('-', $parts);
            $vram = (self::extractVramGb($text) ?: (self::NVIDIA_DEFAULT_VRAM[$model] ?? null));
            if (!$vram) {
                return self::UNKNOWN_KEY;
            }
            return 'nvidia-' . $model . '-' . $vram . 'gb';
        }
        $amd = self::search('\\brx\\s*([5-9]\\d{3})\\s*(gre|xtx|xt)?\\b', $text);
        if ($amd) {
            [$number, $suffix] = $amd->groups();
            $model = ('rx-' . $number . ($suffix ? '-' . $suffix : ''));
            $vram = (self::extractVramGb($text) ?: (self::AMD_DEFAULT_VRAM[$model] ?? null));
            if (!$vram) {
                return self::UNKNOWN_KEY;
            }
            return 'amd-radeon-' . $model . '-' . $vram . 'gb';
        }
        $intel = self::search('\\barc\\s*([ab])\\s*-?\\s*(\\d{3})\\b', $text);
        if ($intel) {
            [$letter, $number] = $intel->groups();
            $model = 'arc-' . $letter . $number;
            $vram = (self::extractVramGb($text) ?: (self::INTEL_DEFAULT_VRAM[$model] ?? null));
            if (!$vram) {
                return self::UNKNOWN_KEY;
            }
            return 'intel-' . $model . '-' . $vram . 'gb';
        }
        return self::UNKNOWN_KEY;
    }

    private static function normaliseAperture(string $value): string
    {
        $raw = str_replace('.', '', str_replace('/', '', mb_strtolower(trim($value))));
        if (str_ends_with($raw, '0') && (self::length($raw) > 1)) {
            $raw = mb_substr($raw, 0, -1);
        }
        return 'f' . $raw;
    }

    private static function extractLensFocal(string $text): ?string
    {
        $match = self::search('\\b(\\d{2,3})\\s*-\\s*(\\d{2,3})\\s*mm\\b', $text);
        if ($match) {
            return $match->group(1) . '-' . $match->group(2) . 'mm';
        }
        $match = self::search('\\b(\\d{2,3})\\s*-\\s*(\\d{2,3})\\b', $text);
        if ($match) {
            return $match->group(1) . '-' . $match->group(2) . 'mm';
        }
        $match = self::search('\\b(\\d{2,3})\\s*mm\\b', $text);
        if ($match) {
            return $match->group(1) . 'mm';
        }
        return null;
    }

    private static function extractLensAperture(string $text): ?string
    {
        $match = self::search('\\bf\\s*/?\\s*(1\\.2|1\\.4|2\\.8|2(?:\\.0)?|4(?:\\.0)?)\\b', $text);
        if ($match) {
            return self::normaliseAperture($match->group(1));
        }
        $match = self::search('\\b1:(1\\.2|1\\.4|2\\.8|2(?:\\.0)?|4(?:\\.0)?)\\b', $text);
        if ($match) {
            return self::normaliseAperture($match->group(1));
        }
        return null;
    }

    public static function cameraLensModelKey(array $attrs, string $title = ''): ?string
    {
        $text = self::text($attrs, $title);
        if (!$text) {
            return self::UNKNOWN_KEY;
        }
        if (self::hasAny($text, self::BOX_ONLY_TERMS) || self::hasAny($text, self::LENS_ACCESSORY_TERMS)) {
            return self::UNKNOWN_KEY;
        }
        if (self::hasAny($text, self::BUNDLE_TERMS) || self::search('\\b(faulty|spares|for parts|for repair)\\b', $text)) {
            return self::UNKNOWN_KEY;
        }
        $focal = self::extractLensFocal($text);
        if (!$focal) {
            return self::UNKNOWN_KEY;
        }
        $aperture = self::extractLensAperture($text);
        if (self::search('\\bsony\\b', $text) && !self::search('\\b(sigma|tamron)\\b', $text)) {
            if ((!str_contains($text, 'fe')) || !self::search('\\b(gm|g master|g lens)\\b', $text)) {
                return self::UNKNOWN_KEY;
            }
            $tier = (self::search('\\b(gm|g master)\\b', $text) ? 'gm' : 'g');
            $parts = ['sony', 'fe', $focal];
            if ($aperture) {
                $parts[] = $aperture;
            }
            $parts[] = $tier;
            if (self::search('\\b(ii|mk2|mark 2)\\b', $text)) {
                $parts[] = 'ii';
            }
            return self::slug(implode('-', $parts));
        }
        if (self::search('\\bcanon\\b', $text)) {
            if ((!str_contains($text, 'rf')) || !self::search('\\bl\\b', $text)) {
                return self::UNKNOWN_KEY;
            }
            $parts = ['canon', 'rf', $focal];
            if ($aperture) {
                $parts[] = $aperture;
            }
            $parts[] = 'l';
            return self::slug(implode('-', $parts));
        }
        if (self::search('\\bnikon\\b', $text)) {
            if ((!self::contains(' ' . $text . ' ', ' z ')) || !self::search('\\bs\\b', $text)) {
                return self::UNKNOWN_KEY;
            }
            $parts = ['nikon', 'z', $focal];
            if ($aperture) {
                $parts[] = $aperture;
            }
            $parts[] = 's';
            return self::slug(implode('-', $parts));
        }
        if (self::search('\\b(fujifilm|fuji)\\b', $text)) {
            $family = (self::search('\\bgf\\b', $text) ? 'gf' : (self::search('\\bxf\\b', $text) ? 'xf' : ''));
            if (!$family) {
                return self::UNKNOWN_KEY;
            }
            $parts = ['fujifilm', $family, $focal];
            if ($aperture) {
                $parts[] = $aperture;
            }
            return self::slug(implode('-', $parts));
        }
        if (self::search('\\bleica\\b', $text)) {
            $familyMatch = self::search('\\b(summicron|summilux|noctilux|elmarit)\\b', $text);
            $mount = (self::search('\\bm\\b', $text) ? 'm' : (self::search('\\bsl\\b', $text) ? 'sl' : ''));
            if (!$familyMatch || !$mount) {
                return self::UNKNOWN_KEY;
            }
            return self::slug('leica-' . $familyMatch->group(1) . '-' . $mount . '-' . $focal);
        }
        if (self::search('\\bhasselblad\\b', $text)) {
            if (!self::search('\\bxcd\\b', $text)) {
                return self::UNKNOWN_KEY;
            }
            $parts = ['hasselblad', 'xcd', $focal];
            if ($aperture) {
                $parts[] = $aperture;
            }
            return self::slug(implode('-', $parts));
        }
        if (self::search('\\bsigma\\b', $text)) {
            if (!str_contains($text, 'art')) {
                return self::UNKNOWN_KEY;
            }
            $parts = ['sigma', $focal];
            if ($aperture) {
                $parts[] = $aperture;
            }
            if (str_contains($text, 'dg dn')) {
                array_push($parts, ...['dg', 'dn']);
            }
            $parts[] = 'art';
            return self::slug(implode('-', $parts));
        }
        if (self::search('\\btamron\\b', $text)) {
            if (!$aperture || !self::search('\\b(sony|e mount|z mount|rf)\\b', $text)) {
                return self::UNKNOWN_KEY;
            }
            $parts = ['tamron', $focal, $aperture];
            if ((str_contains($text, 'sony')) || (str_contains($text, 'e mount'))) {
                $parts[] = 'sony-e';
            } else {
                if (str_contains($text, 'z mount')) {
                    $parts[] = 'nikon-z';
                } else {
                    if (str_contains($text, 'rf')) {
                        $parts[] = 'canon-rf';
                    }
                }
            }
            return self::slug(implode('-', $parts));
        }
        return self::UNKNOWN_KEY;
    }

    private static function industrialPartSlug(string $value): string
    {
        return implode('-', self::findAll('[a-z0-9]+', mb_strtolower($value)));
    }

    public static function industrialAutomationModelKey(array $attrs, string $title = ''): ?string
    {
        $text = self::text($attrs, $title);
        if (!$text) {
            return self::UNKNOWN_KEY;
        }
        if (self::hasAny($text, self::BOX_ONLY_TERMS) || self::hasAny($text, self::INDUSTRIAL_ACCESSORY_TERMS)) {
            return self::UNKNOWN_KEY;
        }
        if (self::hasAny($text, self::BUNDLE_TERMS) || self::search('\\b(faulty|spares|for parts|for repair|untested)\\b', $text)) {
            return self::UNKNOWN_KEY;
        }
        $industrialPatterns = [
            ['siemens', '\\b(6es7\\s*\\d{3}[-\\s]?[0-9a-z]{4,5}[-\\s]?[0-9a-z]{4}|6av\\s*\\d{4}[-\\s]?[0-9a-z]{4,5}[-\\s]?[0-9a-z]{4}|cpu\\s*15\\d{2}[a-z]?)\\b'],
            ['allen-bradley', '\\b(1756|1769|1762|5069|2711p|2711r)[-\\s]?[0-9a-z]+(?:[-\\s][0-9a-z]+){0,3}\\b'],
            ['omron', '\\b(cj\\d+[a-z]?[-\\s]?cpu\\d+|cp1[hle][-\\s]?[0-9a-z]+|nj\\d+[-\\s]?[0-9a-z]+)\\b'],
            ['mitsubishi', '\\b(fx[35]u[-\\s]?[0-9a-z]+|mr[-\\s]?j\\d[0-9a-z]?[-\\s]?[0-9a-z]+)\\b'],
            ['schneider', '\\b(bmx[0-9a-z]+|tm\\d+[0-9a-z]+|atv\\d+[0-9a-z]+)\\b'],
            ['beckhoff', '\\b(cx\\d{4}(?:-[0-9a-z]{3,5})?|el\\d{4})\\b'],
            ['pilz', '\\b(pnoz[-\\s]?[0-9a-z]+(?:[-\\s][0-9a-z]+)*)\\b'],
            ['br', '\\b(x20[0-9a-z]+|4pp\\d+[0-9a-z]*)\\b'],
            ['control-techniques', '\\b(unidrive[-\\s]?m\\d{3,4}|sp\\d{4}|mentor[-\\s]?mp\\d+)\\b'],
        ];
        $brandMarkers = [
            'siemens' => ['siemens', 'simatic', 's7-1500', 's7 1500'],
            'allen-bradley' => ['allen bradley', 'allen-bradley', 'rockwell', 'panelview'],
            'omron' => ['omron'],
            'mitsubishi' => ['mitsubishi'],
            'schneider' => ['schneider'],
            'beckhoff' => ['beckhoff'],
            'pilz' => ['pilz'],
            'br' => ['b&r', 'b and r', 'br automation'],
            'control-techniques' => ['control techniques', 'unidrive'],
        ];
        foreach ($industrialPatterns as [$brand, $pattern]) {
            if (!self::containsAny($text, $brandMarkers[$brand])) {
                continue;
            }
            $match = self::search($pattern, $text);
            if ($match) {
                return $brand . '-' . self::industrialPartSlug($match->group(0));
            }
        }
        return self::UNKNOWN_KEY;
    }

    public static function cpuModelKey(array $attrs, string $title = ''): ?string
    {
        $text = self::text($attrs, $title);
        if (!$text) {
            return self::UNKNOWN_KEY;
        }
        if (self::hasAny($text, self::BOX_ONLY_TERMS) || self::hasAny($text, self::CPU_ACCESSORY_TERMS)) {
            return self::UNKNOWN_KEY;
        }
        if (self::hasAny($text, self::BUNDLE_TERMS)) {
            return self::UNKNOWN_KEY;
        }
        if (self::search('\\b(xeon|epyc|opteron|server|laptop|notebook|mobile)\\b', $text)) {
            return self::UNKNOWN_KEY;
        }
        if ((str_contains($text, 'compatible with')) || (str_contains($text, 'supports ')) || (str_contains($text, 'support for'))) {
            return self::UNKNOWN_KEY;
        }
        if (self::search('\\b(bent|missing|damaged)\\s+(pin|pins|pad|pads)\\b', $text)) {
            return self::UNKNOWN_KEY;
        }
        $amd = self::search('\\b(?:amd\\s+)?ryzen\\s*([3579])\\s*([0-9]{4,5})\\s*(x3d|xt|x|g|ge)?\\b', $text);
        if ($amd) {
            [$tier, $number, $suffix] = $amd->groups();
            if (intval(mb_substr($number, 0, 1)) < 3) {
                return self::UNKNOWN_KEY;
            }
            return 'amd-ryzen-' . $tier . '-' . $number . ($suffix ?: '');
        }
        $intel = self::search('\\b(?:intel\\s+)?(?:core\\s*)?i([3579])[-\\s]*([0-9]{4,5})(ks|kf|k|f|t)?\\b', $text);
        if ($intel) {
            [$tier, $number, $suffix] = $intel->groups();
            $generation = ((self::length($number) >= 5) ? intval(mb_substr($number, 0, 2)) : intval(mb_substr($number, 0, 1)));
            if ($generation < 10) {
                return self::UNKNOWN_KEY;
            }
            return 'intel-core-i' . $tier . '-' . $number . ($suffix ?: '');
        }
        return self::UNKNOWN_KEY;
    }

    private const MOTHERBOARD_STOP_TOKENS = [
        '0',
        '12th',
        '13th',
        '14th',
        '15th',
        '1x',
        '5',
        '6',
        '7',
        'am4',
        'am5',
        'amd',
        'atx',
        'board',
        'box',
        'boxed',
        'condition',
        'core',
        'damaged',
        'faulty',
        'gaming',
        'i3',
        'i5',
        'i7',
        'i9',
        'intel',
        'itx',
        'lga',
        'lga1200',
        'lga1700',
        'mainboard',
        'matx',
        'micro',
        'mini',
        'motherboard',
        'new',
        'pc',
        'pcie',
        'perfect',
        'ready',
        'ryzen',
        'sata',
        'sata3',
        'socket',
        'untested',
        'used',
    ];

    private static function normaliseMotherboardTokens(string $text): array
    {
        $text = str_replace('wi-fi', 'wifi', $text);
        $text = self::replacePattern('\\b(?:ddr)\\s*([45])\\b', 'ddr\\1', $text);
        $text = self::replacePattern('\\bd([45])\\b', 'ddr\\1', $text);
        $text = self::replacePattern('\\brev(?:ision)?\\.?\\s*([0-9])\\.?([0-9])\\b', 'rev \\1 \\2', $text);
        $rawTokens = self::findAll('[a-z0-9]+', mb_strtolower($text));
        $tokens = [];
        $skipNext = false;
        foreach ($rawTokens as $idx => $tok) {
            if ($skipNext) {
                $skipNext = false;
                continue;
            }
            if (($tok === 'wi') && (($idx + 1) < self::length($rawTokens)) && ($rawTokens[($idx + 1)] === 'fi')) {
                $tokens[] = 'wifi';
                $skipNext = true;
                continue;
            }
            $tokens[] = $tok;
        }
        return $tokens;
    }

    public static function motherboardModelKey(array $attrs, string $title = ''): ?string
    {
        $text = self::text($attrs, $title);
        if (!$text) {
            return self::UNKNOWN_KEY;
        }
        if (self::hasAny($text, self::BOX_ONLY_TERMS) || self::hasAny($text, self::MOTHERBOARD_ACCESSORY_TERMS)) {
            return self::UNKNOWN_KEY;
        }
        if (self::hasAny($text, self::BUNDLE_TERMS)) {
            return self::UNKNOWN_KEY;
        }
        if (self::search('\\b(cpu|processor|ram|memory)\\s+(included|bundle|combo)\\b', $text)) {
            return self::UNKNOWN_KEY;
        }
        if (self::search('\\b(laptop|server|proprietary|oem)\\s+motherboard\\b', $text)) {
            return self::UNKNOWN_KEY;
        }
        $brandAliases = ['asus' => 'asus', 'msi' => 'msi', 'gigabyte' => 'gigabyte', 'asrock' => 'asrock'];
        $brand = '';
        $attrBrand = mb_strtolower(self::clean(($attrs['Brand'] ?? null)));
        foreach ($brandAliases as $raw => $canonical) {
            if ((str_contains($attrBrand, $raw)) || self::search('\\b' . $raw . '\\b', $text)) {
                $brand = $canonical;
                break;
            }
        }
        if (!$brand) {
            return self::UNKNOWN_KEY;
        }
        if (($brand !== 'asus') && self::search('\\b(rog|strix|tuf)\\b', $text)) {
            return self::UNKNOWN_KEY;
        }
        $tokens = self::normaliseMotherboardTokens($text);
        $tokens = array_values(array_filter($tokens, static fn($tok) => ($tok !== $brand)));
        $chipsetIdx = null;
        foreach ($tokens as $idx => $tok) {
            if (self::fullMatch('(a|b|x|z|h|q)\\d{3,4}[a-z]?', $tok)) {
                $chipsetIdx = $idx;
                break;
            }
        }
        if ($chipsetIdx === null) {
            return self::UNKNOWN_KEY;
        }
        $start = max(0, ($chipsetIdx - 3));
        $end = min(self::length($tokens), ($chipsetIdx + 7));
        $productTokens = [];
        $seenChipset = false;
        foreach (array_slice($tokens, $start, ($end - $start)) as $tok) {
            if ((self::contains(self::MOTHERBOARD_STOP_TOKENS, $tok)) || !$tok || self::fullMatch('\\d{3,4}', $tok)) {
                continue;
            }
            if ($tok === 'ddr5') {
                continue;
            }
            if (self::fullMatch('(a|b|x|z|h|q)\\d{3,4}[a-z]?', $tok)) {
                if ($seenChipset) {
                    continue;
                }
                $seenChipset = true;
            }
            if (!in_array($tok, $productTokens, true)) {
                $productTokens[] = $tok;
            }
        }
        if ((in_array('wifi', $tokens, true)) && (!in_array('wifi', $productTokens, true))) {
            $productTokens[] = 'wifi';
        }
        if ((in_array('ddr4', $tokens, true)) && (!in_array('ddr4', $productTokens, true))) {
            $productTokens[] = 'ddr4';
        }
        if ((in_array('v2', $tokens, true)) && (!in_array('v2', $productTokens, true))) {
            $productTokens[] = 'v2';
        }
        if (in_array('rev', $tokens, true)) {
            $revIdx = array_search('rev', $tokens, true);
            if (($revIdx + 2) < self::length($tokens)) {
                $rev = 'rev-' . $tokens[($revIdx + 1)] . '-' . $tokens[($revIdx + 2)];
                if (!in_array($rev, $productTokens, true)) {
                    $productTokens[] = $rev;
                }
            }
        }
        if (!$productTokens || !self::any($productTokens, static fn($tok) => str_starts_with($tok, $tokens[$chipsetIdx]))) {
            return self::UNKNOWN_KEY;
        }
        if (self::length(array_values(array_filter($productTokens, static fn($tok) => ($tok !== $tokens[$chipsetIdx])))) < 1) {
            return self::UNKNOWN_KEY;
        }
        return $brand . '-' . self::slug(implode('-', $productTokens));
    }
}
