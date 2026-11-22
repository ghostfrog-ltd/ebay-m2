<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey;

final class AppleMac
{
    private const LAPTOP_RAM = [4, 6, 8, 12, 16, 18, 24, 32, 36, 48, 64, 96, 128];
    private const SSD_LABEL = 'ssd|storage|hard\s*drive|hdd|flash';

    public function key(array $attrs, string $title, string $line): string
    {
        $reportedLines = array_filter(array_map(self::line(...), Evidence::texts(
            $attrs, '', 'Product Line', 'Series', 'Model', 'Type'
        )));
        if (!Evidence::subset($reportedLines, [$line, 'macbook'])) {
            return 'unknown';
        }

        $chips = [];
        foreach (Evidence::texts($attrs, $title, 'Processor', 'Chipset Model', 'Processor Model', 'CPU') as $text) {
            array_push($chips, ...$this->chips($text));
        }
        $chips = Evidence::unique($chips);
        if (array_intersect($chips, ['core-i3', 'core-i5', 'core-i7', 'core-i9', 'core-m3'])) {
            $chips = array_diff($chips, ['intel']);
        }
        if (count($chips) > 1) {
            return 'unknown';
        }
        $chip = (string)reset($chips);
        $laptop = str_starts_with($line, 'macbook');
        $ram = $this->ram($attrs, $title, $laptop);
        $ssd = $this->storage($attrs, $title);
        if ($ram === '' || $ssd === '' || $ram === $ssd || $ssd === '1gb') {
            return 'unknown';
        }

        return $laptop
            ? $this->laptop($attrs, $title, $line, $chip, $ram, $ssd)
            : $this->desktop($attrs, $title, $line, $chip, $ram, $ssd);
    }

    public static function line(string $text): string
    {
        if (!preg_match('~\b(macbook(?:[ -]+(?:air|pro|neo))?|imac(?:[ -]+pro)?|mac[ -]*(?:mini|studio|pro))\b~', $text, $match)) {
            return '';
        }
        return preg_replace('~^mac(?=mini|studio|pro)~', 'mac-', preg_replace('~[ -]+~', '-', $match[1]));
    }

    private function chips(string $text): array
    {
        if (preg_match('~\bcore\s*m3\b|\bm3-\d|\bm3\s*y\d{2}\b~', $text)) {
            return ['core-m3'];
        }
        $chips = [];
        foreach (Evidence::matches('~\b(?:intel\s*)?(?:core\s*)?i([3579])\b~', [$text]) as $intel) {
            $chips[] = 'core-i' . $intel;
        }
        if (preg_match('~\b(?:intel|core)\s+m[1-5]\b~', $text)) {
            $chips[] = 'intel';
        } else {
            preg_match_all('~\b(?:apple\s*)?m([1-5])\s*(pro|max|ultra)?\b~', $text, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $chips[] = 'm' . $match[1] . ($match[2] ?? '');
            }
        }
        return $chips === [] && str_contains($text, 'intel') ? ['intel'] : $chips;
    }

    private function ram(array $attrs, string $title, bool $laptop): string
    {
        $structured = Evidence::matches('~\b(\d{1,4})\s*gb\b~', Evidence::texts($attrs, '', 'RAM Size', 'Memory', 'RAM'));
        if ($laptop && array_filter($structured, static fn($amount) => (int)$amount > 128)) {
            return '';
        }
        $labelled = Evidence::matches('~\b(\d{1,3})\s*gb\s*(?:ram(?=\b|\d)|unified\s+memory\b|memory\b)~', [$title]);
        $allowed = $laptop ? self::LAPTOP_RAM : array_merge(self::LAPTOP_RAM, [192, 256, 512, 768]);
        $explicit = array_values(array_intersect(Evidence::unique(array_merge($structured, $labelled)), $allowed));
        if ($explicit !== []) {
            return count($explicit) === 1 ? $explicit[0] . 'gb' : '';
        }
        $paired = Evidence::matches('~\b(\d{1,3})\s*gb\s*(?:/|,\s*)\s*\d{2,4}\s*(?:gb|tb)\b~', [$title]);
        if ($paired === [] && preg_match('~\b(?:\d{3,4}\s*gb|\d{1,2}\s*tb)\b~', $title)) {
            $paired = Evidence::matches('~\b(\d{1,3})\s*gb\b(?!\s*(?:ssd|storage|vram))~', [$title]);
        }
        $paired = array_values(array_intersect($paired, $allowed));
        return count($paired) === 1 ? $paired[0] . 'gb' : '';
    }

    private function storage(array $attrs, string $title): string
    {
        $structured = Evidence::texts($attrs, '', 'Storage Capacity', 'SSD Capacity', 'Hard Drive Capacity', 'Capacity');
        $disks = [];
        foreach ($structured as $text) {
            preg_match_all('~\b(\d{1,4})\s*(gb|tb)\b~', $text, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $disks[] = $this->capacity((int)$match[1], $match[2]);
            }
        }
        preg_match_all('~\b(\d{1,4})\s*(gb|tb)\s*(?:' . self::SSD_LABEL . ')\b~', $title, $titleDisks, PREG_SET_ORDER);
        if ($titleDisks === []) {
            preg_match_all('~\b(?:' . self::SSD_LABEL . ')\s*[:=]?\s*(\d{1,4})\s*(gb|tb)\b~', $title, $titleDisks, PREG_SET_ORDER);
        }
        if ($titleDisks === []) {
            preg_match_all('~\b(\d{1,4})\s*(gb|tb)\b(?!\s*(?:ram|unified memory|vram))~', $title, $capacities, PREG_SET_ORDER);
            $titleDisks = array_filter($capacities, static fn($match) => $match[2] === 'tb' || (int)$match[1] >= 240);
        }
        foreach ($titleDisks as $match) {
            $disks[] = $this->capacity((int)$match[1], $match[2]);
        }
        $disks = Evidence::unique($disks);
        return count($disks) === 1 ? $disks[0] : '';
    }

    private function capacity(int $amount, string $unit): string
    {
        if ($unit === 'gb') {
            return match ($amount) {
                240, 250, 251, 255, 256 => '256gb',
                480, 500, 512 => '512gb',
                960, 1000, 1024 => '1tb',
                default => $amount . $unit,
            };
        }
        return $amount . $unit;
    }

    private function gpu(array $attrs, string $title): array
    {
        return Evidence::unique(array_map('intval', array_merge(
            Evidence::matches('~\b(\d{1,2})[ -]*(?:c(?:ores?)?[ -]*)?gpu\b~',
                Evidence::texts($attrs, $title, 'GPU', 'Graphics Processing Type')),
            Evidence::matches('~^(?:apple\s+)?(\d{1,2})[ -]*cores?$~', Evidence::texts($attrs, '', 'GPU'))
        )));
    }

    private function laptop(array $attrs, string $title, string $line, string $chip, string $ram, string $ssd): string
    {
        $model = Evidence::texts($attrs, $title, 'MPN', 'Model', 'Processor Model', 'Model Number', 'Type');
        $ids = Evidence::identifiers($model);
        $gpu = $this->gpu($attrs, $title);
        if (count($ids) > 1 || count($gpu) > 1) {
            return 'unknown';
        }
        $screens = Evidence::texts($attrs, $title, 'Screen Size', 'Display');
        $bare = Evidence::matches('~^(\d{2}(?:\.\d)?)$~', Evidence::texts($attrs, '', 'Screen Size'));
        $short = Evidence::matches('~\bmacbook(?:[ -]+(?:air|pro|neo))?[ -]+(1[3-6](?:\.[236])?)\b(?![.\d]|\s*(?:gb|tb)\b)~', [$title]);
        $screens = Evidence::screens(array_merge($screens, array_map(static fn($size) => $size . 'in', array_merge($bare, $short))));

        if ($line === 'macbook-neo' || in_array('a3404', $ids, true)) {
            return in_array($line, ['macbook', 'macbook-neo'], true)
                ? $this->neo($attrs, $title, $chip, $ram, $ssd, $ids, $screens, $gpu) : 'unknown';
        }

        $airIds = array_column(AppleCatalog::MACBOOK_AIRS, 0);
        $airChips = array_column(AppleCatalog::MACBOOK_AIRS, 1);
        if (array_intersect($ids, $airIds) || ($line === 'macbook-air' && in_array($chip, $airChips, true))) {
            return in_array($line, ['macbook', 'macbook-air'], true)
                ? $this->air($chip, $ram, $ssd, $ids, $screens, $gpu) : 'unknown';
        }
        if (preg_match('~^core-(?:i[3579]|m3)$~', $chip) && count($ids) === 1 && count($screens) === 1
            && !array_intersect($ids, array_column(AppleCatalog::MACBOOK_M5_PRO_CONFIGS, 0))
        ) {
            foreach (AppleCatalog::MACBOOK_PROS as [$chassis]) {
                if (in_array($ids[0], explode(' ', $chassis), true)) {
                    return 'unknown';
                }
            }
            return implode('-', ['apple', $line, $ids[0], $screens[0], $chip, $ram, $ssd]);
        }
        if (!in_array($line, ['macbook', 'macbook-pro'], true)) {
            return 'unknown';
        }

        $partIds = [];
        foreach (AppleCatalog::MACBOOK_M5_PRO_PARTS as $stems => $chassis) {
            if (Evidence::any('~\b(?:' . str_replace(' ', '|', $stems) . ')(?:[a-z]{1,3}(?:/a)?)?\b~', $model)) {
                $partIds[] = $chassis;
            }
        }
        if ($partIds !== [] || array_intersect($ids, array_column(AppleCatalog::MACBOOK_M5_PRO_CONFIGS, 0))
            || in_array($chip, ['m5', 'm5pro', 'm5max'], true)
        ) {
            return $this->m5Pro($attrs, $title, $chip, $ram, $ssd, $ids, $screens, $gpu, $partIds);
        }

        $candidates = [];
        if ($chip === '' && $ids === []) {
            return 'unknown';
        }
        foreach (AppleCatalog::MACBOOK_PROS as [$codes, $cpu, $fixed, $memory, $capacities]) {
            if (($chip !== '' && $chip !== $cpu) || !$this->supports($ram, $memory) || !$this->supports($ssd, $capacities)) {
                continue;
            }
            $diagonals = $fixed !== '' ? [$fixed] : ['142in', '162in'];
            foreach (explode(' ', $codes) as $index => $chassis) {
                $size = $diagonals[$index];
                $allowedGpu = AppleCatalog::MACBOOK_PRO_GPU_CORES[$cpu];
                if (Evidence::subset($ids, [$chassis]) && Evidence::subset($screens, [$size, substr($size, 0, 2) . 'in'])
                    && Evidence::subset($gpu, $allowedGpu)
                ) {
                    $variant = $gpu !== [] && count($allowedGpu) > 1 ? '-' . $gpu[0] . 'gpu' : '';
                    $candidates[] = "apple-macbook-pro-$chassis-$size-$cpu$variant-$ram-$ssd";
                }
            }
        }
        return Evidence::only($candidates);
    }

    private function air(string $chip, string $ram, string $ssd, array $ids, array $screens, array $gpu): string
    {
        $candidates = [];
        foreach (AppleCatalog::MACBOOK_AIRS as [$chassis, $cpu, $size, $aliases, $memory]) {
            $capacities = $cpu === 'm5' ? '512gb 1tb 2tb 4tb' : '256gb 512gb 1tb 2tb';
            if (!Evidence::subset($ids, [$chassis]) || ($chip !== $cpu && !($chip === '' && $ids === [$chassis]))
                || !Evidence::subset($screens, explode(' ', $aliases))
                || !$this->supports($ram, $memory) || !$this->supports($ssd, $capacities)
            ) {
                continue;
            }
            $allowedGpu = $cpu === 'm1' ? [7, 8] : [8, 10];
            if ($cpu === 'm5' && $size === '153in') {
                $allowedGpu = [10];
            }
            if (!Evidence::subset($gpu, $allowedGpu) || ($cpu === 'm5' && count($allowedGpu) > 1 && $gpu === [])) {
                continue;
            }
            $variant = $gpu !== [] && !($cpu === 'm5' && count($allowedGpu) === 1) ? '-' . $gpu[0] . 'gpu' : '';
            $candidates[] = "apple-macbook-air-$chassis-$size-$cpu$variant-$ram-$ssd";
        }
        return Evidence::only($candidates);
    }

    private function m5Pro(
        array $attrs, string $title, string $chip, string $ram, string $ssd,
        array $ids, array $screens, array $gpu, array $partIds
    ): string {
        $display = Evidence::texts($attrs, $title, 'Display', 'Display Type', 'Screen Size', 'Model');
        $panels = [];
        if (Evidence::any('~\b(?:proled|oled)\b~', $display)) {
            $panels[] = 'proled';
        }
        if (Evidence::any('~\b(?:liquid retina xdr|mini[ -]?led)\b~', $display)) {
            $panels[] = 'liquid';
        }
        $cores = Evidence::matches('~\b(\d{1,2})[ -]*c(?:ores?)?[ -]*cpu\b~', Evidence::texts($attrs, $title, 'Processor', 'CPU'));
        $candidates = [];
        foreach (AppleCatalog::MACBOOK_M5_PRO_CONFIGS as [$chassis, $size, $cpu, $panel, $count, $memory, $capacities]) {
            if (($chip !== '' && $chip !== $cpu) || !Evidence::subset($ids, [$chassis])
                || array_filter($partIds, static fn($allowed) => !in_array($chassis, $allowed, true))
                || !Evidence::subset($screens, [$size, substr($size, 0, 2) . 'in'])
                || !Evidence::subset($panels, [$panel]) || !Evidence::subset($gpu, [$count])
                || !Evidence::subset($cores, [$cpu === 'm5' ? 10 : ($count === 16 ? 15 : 18)])
                || !$this->supports($ram, $memory) || !$this->supports($ssd, $capacities)
            ) {
                continue;
            }
            $variant = $cpu === 'm5' ? '' : '-' . $count . 'gpu';
            $candidates[] = "apple-macbook-pro-$chassis-$size-$cpu$variant-$ram-$ssd";
        }
        return Evidence::only($candidates);
    }

    private function neo(array $attrs, string $title, string $chip, string $ram, string $ssd, array $ids, array $screens, array $gpu): string
    {
        $processors = Evidence::texts($attrs, $title, 'Processor', 'CPU', 'Processor Model', 'Chipset Model');
        $series = array_map(static fn($part) => str_replace([' ', '-'], '', $part),
            Evidence::matches('~\b(a\d{2}(?:[ -]*pro)?)\b~', $processors));
        $cores = Evidence::matches('~\b(\d{1,2})[ -]*c(?:ores?)?[ -]*cpu\b~', $processors);
        $texts = Evidence::texts($attrs, $title, 'Model', 'Features');
        $without = '~\b(?:no|without|non)[ -]+touch[ -]*id\b~';
        if ($chip !== '' || !Evidence::subset($series, ['a18pro']) || !Evidence::subset($cores, [6])
            || !Evidence::subset($ids, ['a3404']) || !Evidence::subset($screens, ['13in']) || !Evidence::subset($gpu, [5])
            || $ram !== '8gb' || !in_array($ssd, ['256gb', '512gb'], true)
            || ($ssd === '512gb' && Evidence::any($without, $texts))
            || ($ssd === '256gb' && Evidence::any('~\btouch[ -]*id\b~', preg_replace($without, '', $texts)))
        ) {
            return 'unknown';
        }
        return "apple-macbook-neo-a3404-13in-a18pro-8gb-$ssd";
    }

    private function desktop(array $attrs, string $title, string $line, string $chip, string $ram, string $ssd): string
    {
        $model = Evidence::texts($attrs, $title, 'Model', 'Model Identifier', 'MPN');
        $ids = Evidence::identifiers($model);
        $screens = Evidence::screens(Evidence::texts($attrs, $title, 'Screen Size', 'Display'));
        $gpu = $this->gpu($attrs, $title);
        $identifiers = [];
        foreach ($model as $text) {
            preg_match_all('~\b(macmini|imac|macpro|mac)\s*(\d+)[,.-](\d+)\b~', $text, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $family, $major, $minor]) {
                $identifiers[] = "$family$major-$minor";
            }
        }
        $identifiers = Evidence::unique($identifiers);
        if ($line === 'mac-mini' && $chip === 'core-i5' && ($identifiers === ['macmini7-1']
            || ($ids === ['a1347'] && Evidence::any('~\blate[ -]*2014\b~', $model)))
        ) {
            $clocks = Evidence::matches('~\b(1\.4|2\.6|2\.8)\s*ghz\b~', Evidence::texts($attrs, $title, 'Processor', 'Processor Speed', 'CPU'));
            if (count($clocks) === 1 && Evidence::subset($ids, ['a1347']) && Evidence::subset($identifiers, ['macmini7-1'])
                && $this->supports($ram, '4gb 8gb 16gb') && $this->supports($ssd, '256gb 512gb 1tb 2tb')
            ) {
                return 'apple-mac-mini-macmini7-1-core-i5-' . str_replace('.', '', $clocks[0]) . "ghz-$ram-$ssd";
            }
            return 'unknown';
        }
        $build = $line === 'mac-pro' ? Evidence::matches('~\b(tower|rack)(?:\b|mount)~', $model) : [];
        $ports = $line === 'imac' ? Evidence::matches('~\b(2|4|two|four)[ -]+(?:thunderbolt[ -]+)?ports\b~', $model) : [];
        $ports = str_replace(['two', 'four'], ['2', '4'], $ports);
        $candidates = [];
        foreach (AppleCatalog::MAC_DESKTOPS as [$family, $identifier, $codes, $size, $cpu, $cores, $memory, $capacities]) {
            $suffixPosition = strrpos($identifier, '-');
            if ($family !== $line || $chip !== $cpu || !$this->supports($ram, $memory) || !$this->supports($ssd, $capacities)
                || !Evidence::subset($ids, explode(' ', $codes))
                || !Evidence::subset($identifiers, [$identifier, substr($identifier, 0, $suffixPosition)])
                || !Evidence::subset($gpu, [$cores]) || ($size !== '' && !Evidence::subset($screens, [$size, '235in']))
                || !Evidence::subset($build, [substr($identifier, $suffixPosition + 1)])
                || !Evidence::subset($ports, [in_array($identifier, ['imac21-2', 'mac15-4', 'mac16-2'], true) ? '2' : '4'])
            ) {
                continue;
            }
            $candidates[] = implode('-', array_filter(['apple', $family, $identifier, $size, $cpu, $cores . 'gpu', $ram, $ssd]));
        }
        return Evidence::only($candidates);
    }

    private function supports(string $value, string $choices): bool
    {
        return in_array($value, explode(' ', $choices), true);
    }
}
