<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey\Rules;

use GhostFrog\Ebay\Model\ModelKey\Evidence;

final class RetroPc
{
    public static function key(array $attrs, string $title): string
    {
        $texts = Evidence::texts($attrs, $title, 'Brand', 'Model', 'MPN', 'Type', 'Memory Size',
            'Compatible Port/Slot', 'Bus Type', 'Clock Speed', 'Processor Model', 'Chipset Model');
        if (Evidence::any('~\b(?:rtx|gtx|ryzen|ddr[45]|nvme|pcie|gaming pc|for parts|parts only|spares or repair|'
            . 'repair only|untested|faulty|box only|empty box|heatsink only|cooler only|fan only|bracket only|manual only|compatible with|job lot)\b~', $texts)
        ) {
            return 'unknown';
        }

        $graphics = self::graphics($texts);
        $sound = self::sound($texts);
        $processors = self::processor($texts);
        $matches = array_filter([$graphics, $sound, $processors]);
        return Evidence::only($matches);
    }

    private static function graphics(array $texts): string
    {
        $patterns = [
            '3dfx-' => '~\b(voodoo[ -]*(?:banshee|2|3[ -]+(?:2000|3000|3500)|4[ -]+4500|5[ -]+(?:5500|6000)))\b~',
            'nvidia-' => '~\b((?:riva[ -]+)?tnt2(?:[ -]+(?:ultra|m64))?|geforce[ -]*[234][ -]*(?:gts|ti[ -]*\d{3}|mx[ -]*\d{2,3}))\b~',
            'matrox-' => '~\b(millennium[ -]+g[245]\d{2}|g(?:200|400|450|550))\b~',
            'ati-' => '~\b(rage[ -]*128[ -]+(?:pro|vr|gl)|rage[ -]+pro[ -]+turbo)\b~',
            'tseng-' => '~\b(et4000[ -]*(?:w32[pi]?|ax))\b~',
        ];
        $variants = [];
        foreach ($patterns as $vendor => $pattern) {
            foreach (Evidence::matches($pattern, $texts) as $variant) {
                $variant = preg_replace('~^(?:riva|millennium)[ -]+~', '', $variant);
                $variant = preg_replace('~^(geforce|voodoo|et4000)[ -]+(?=\d|w32|ax)~', '$1', $variant);
                $variants[] = $vendor . self::slug($variant);
            }
        }
        $memory = Evidence::matches('~\b(\d{1,3})[ -]*mb\b~', $texts);
        $slots = Evidence::matches('~\b(agp|pci|isa|vlb)\b~', $texts);
        if (count(Evidence::unique($variants)) !== 1 || count($memory) !== 1 || count($slots) !== 1) {
            return '';
        }
        return "retro-graphics-{$variants[0]}-{$memory[0]}mb-{$slots[0]}";
    }

    private static function sound(array $texts): string
    {
        if (!Evidence::any('~\b(?:creative|sound[ -]*blaster|awe(?:32|64))\b~', $texts)) {
            return '';
        }
        $models = Evidence::matches('~\b(ct[ -]*\d{4})\b~', $texts);
        $models = Evidence::unique(str_replace([' ', '-'], '', $models));
        return count($models) === 1 ? 'retro-sound-creative-' . $models[0] : '';
    }

    private static function processor(array $texts): string
    {
        $vendors = Evidence::matches('~\b(intel|amd|cyrix)\b~', $texts);
        $chips = Evidence::matches('~\b((?:i)?48[36][ -]*(?:dx[24]?|sx[2]?|slc|dlc)|'
            . 'pentium[ -]+(?:iii|ii|mmx)|k6-[23]\+?|duron|athlon[ -]+xp)(?![a-z0-9+])~', $texts);
        $chips = Evidence::unique(array_map(static fn($chip) => self::slug(str_replace('+', '-plus', $chip)), $chips));
        $speed = Evidence::matches('~\b(\d{2,4})[ -]*mhz\b~', $texts);
        if (count($vendors) !== 1 || count($chips) !== 1 || count($speed) !== 1
            || !Evidence::any('~\b(?:cpu|processor)\b~', $texts)
        ) {
            return '';
        }
        return "retro-cpu-{$vendors[0]}-{$chips[0]}-{$speed[0]}mhz";
    }

    private static function slug(string $value): string
    {
        return trim(preg_replace('~[^a-z0-9]+~', '-', $value), '-');
    }
}
