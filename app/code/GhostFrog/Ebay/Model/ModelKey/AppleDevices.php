<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey;

final class AppleDevices
{
    public function iphone(array $attrs, string $title): string
    {
        $texts = Evidence::texts($attrs, $title, 'Model', 'Product Line', 'Series');
        $variants = [];
        $pattern = '~\biphone[ -]*(\d{1,2}[es]?|xs(?:[ -]+max)?|xr|x|se|duo)\b(?:[ -]+(pro[ -]*max|pro|max|plus|mini|air)\b)?~';
        foreach ($texts as $text) {
            preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $base = str_replace(' ', '-', $match[1][0]);
                $tier = $match[2][0] ?? '';
                if ($base === 'se') {
                    $numbers = Evidence::generations([$text]);
                    foreach ([2016 => 1, 2020 => 2, 2022 => 3] as $year => $generation) {
                        if (str_contains($text, (string)$year)) {
                            $numbers[] = $generation;
                        }
                    }
                    if (preg_match('~^[ -]*(1|2|3)\b(?!\s*gb)~', substr($text, $match[0][1] + strlen($match[0][0])), $direct)) {
                        $numbers[] = $direct[1];
                    }
                    array_push($variants, ...array_map(static fn($number) => 'se-' . $number, $numbers));
                } else {
                    $tier = preg_replace('~^pro[ -]*max$~', 'pro-max', $tier);
                    $variants[] = $tier !== '' ? "$base-$tier" : $base;
                }
            }
            if (preg_match('~\biphone[ -]+air\b~', $text)) {
                $variants[] = '17-air';
            }
        }
        $ids = Evidence::identifiers(Evidence::texts($attrs, $title, 'Model', 'Model Number', 'MPN'));
        foreach (AppleCatalog::IPHONE_MODEL_NUMBERS as $model => $numbers) {
            if (array_intersect($ids, explode(' ', $numbers))) {
                $variants[] = (string)$model;
            }
        }
        $variant = Evidence::only(Evidence::unique($variants));
        if (isset(AppleCatalog::IPHONE_MODEL_NUMBERS[$variant])
            && !Evidence::subset($ids, explode(' ', AppleCatalog::IPHONE_MODEL_NUMBERS[$variant]))
        ) {
            return 'unknown';
        }
        if ($variant === 'duo') {
            $chips = Evidence::matches('~\b(a\d{2}(?:[ -]*pro)?)\b~', Evidence::texts($attrs, $title, 'Chipset Model', 'Processor'));
            $sizes = Evidence::screens(Evidence::texts($attrs, $title, 'Screen Size', 'Display'));
            if (!Evidence::subset(str_replace([' ', '-'], '', $chips), ['a20pro'])
                || !Evidence::subset($sizes, ['54in', '536in', '76in', '758in'])
            ) {
                return 'unknown';
            }
        }
        $storage = $this->storage($attrs, $title);
        return in_array($storage, explode(' ', AppleCatalog::IPHONE_STORAGE[$variant] ?? ''), true) && $storage !== ''
            ? "apple-iphone-$variant-$storage" : 'unknown';
    }

    public function ipad(array $attrs, string $title): string
    {
        $texts = Evidence::texts($attrs, $title, 'Model', 'Product Line', 'Series', 'MPN');
        $suffixes = Evidence::matches('~\bipad[ -]+(pro|air|mini)\b~', $texts);
        if (count($suffixes) > 1 || ($suffixes !== [] && preg_match('~\bipad\s*[( -]*(?:a16|(?:10|11)th\s+gen)~', $title))) {
            return 'unknown';
        }
        $line = $suffixes === [] ? 'ipad' : 'ipad-' . $suffixes[0];
        $generations = array_merge(Evidence::generations($texts), Evidence::matches(
            '~\bipad(?:[ -]+(?:air|mini))?\s+(\d{1,2})\b(?!\s*(?:[."-]|inch|in\b|gb|tb))~', $texts
        ));
        $chips = str_replace(' ', '', Evidence::matches('~\b(m[1-5]|a(?:17\s*pro|1[0-6][xz]?|[4-9]x?))\b~',
            Evidence::texts($attrs, $title, 'Model', 'Processor', 'Chipset Model', 'CPU')));
        $ids = Evidence::identifiers($texts);
        $screens = Evidence::screens(Evidence::texts($attrs, $title, 'Screen Size', 'Display'));
        $storage = $this->storage($attrs, $title);
        $networks = $this->connectivity([$title]);
        $structured = $this->connectivity(array_map(static fn($field) => implode(' ', Evidence::texts($attrs, '', $field)),
            ['Internet Connectivity', 'Connectivity']));
        if (count($structured) > 1 && in_array('cellular', $structured, true)) {
            $structured = ['cellular'];
        }
        $networks = Evidence::unique(array_merge($networks, $structured));
        if ($storage === '' || ($generations === [] && $chips === [] && $ids === [])) {
            return 'unknown';
        }
        $candidates = [];
        foreach (AppleCatalog::IPADS as [$family, $gen, $chip, $size, $capacities, $wifi, $cellular]) {
            $diagonals = ($size === '109in' || ($size === '11in' && in_array($family, ['ipad', 'ipad-air'], true)))
                ? [$size, '1086in'] : [$size];
            if (($line !== $family && !($line === 'ipad' && $ids !== [] && $generations === [] && $chips === []))
                || !Evidence::subset($generations, [$gen]) || !Evidence::subset($chips, [$chip])
                || !Evidence::subset($screens, $diagonals) || !in_array($storage, explode(' ', $capacities), true)
            ) {
                continue;
            }
            foreach (['wifi' => $wifi, 'cellular' => $cellular] as $kind => $allowedIds) {
                if (($ids !== [] || $networks !== []) && Evidence::subset($ids, explode(' ', $allowedIds))
                    && Evidence::subset($networks, [$kind])
                ) {
                    $identity = str_starts_with($chip, 'm') ? $chip : Evidence::ordinal($gen);
                    $candidates[] = "apple-$family-$size-$identity-$storage-$kind";
                }
            }
        }
        return Evidence::only($candidates);
    }

    public function watch(array $attrs, string $title): string
    {
        $texts = Evidence::texts($attrs, $title, 'Model', 'Product Line', 'Series', 'MPN');
        $ids = Evidence::identifiers($texts);
        $models = [];
        foreach ($texts as $text) {
            foreach (['ultra' => [2022 => 1], 'se' => [2020 => 1, 2022 => 2, 2025 => 3]] as $tier => $years) {
                if (!preg_match('~\b' . $tier . '(?:[ -]*(?:series[ -]*)?([1-9])(?!\d))?\b~', $text, $match)) {
                    continue;
                }
                $generations = Evidence::generations([$text]);
                if (!empty($match[1])) {
                    $generations[] = (int)$match[1];
                }
                foreach ($years as $year => $number) {
                    if (str_contains($text, (string)$year)) {
                        $generations[] = $number;
                    }
                }
                if ($generations === []) {
                    $models[] = $tier . '-unspecified';
                }
                foreach ($generations as $number) {
                    $models[] = $number === 1 ? $tier : $tier . '-' . $number;
                }
            }
            if (!str_contains($text, 'ultra')) {
                foreach (Evidence::matches('~\bseries[ -]*(\d{1,2})\b~', [$text]) as $series) {
                    $models[] = 'series-' . $series;
                }
            }
        }
        if ($ids === [] && !array_filter($models, static fn($model) => !str_ends_with($model, '-unspecified'))) {
            return 'unknown';
        }
        $sizes = Evidence::matches('~\b(\d{2})\s*mm\b~', Evidence::texts($attrs, $title, 'Case Size'));
        array_push($sizes, ...array_filter(Evidence::texts($attrs, '', 'Case Size'), 'ctype_digit'));
        $networks = $this->connectivity(Evidence::texts($attrs, $title, 'Model', 'Connectivity', 'Network'), true);
        if (in_array('gps-unspecified', $networks, true)) {
            $networks = array_diff($networks, ['gps-unspecified']);
            if ($networks === [] && !array_filter($models, static fn($model) => str_starts_with($model, 'ultra'))) {
                $networks[] = 'gps';
            }
        }
        $materials = Evidence::unique(array_map(static fn($value) => match (true) {
            str_starts_with($value, 'alu') => 'aluminium',
            str_starts_with($value, 'stainless') => 'steel',
            default => $value,
        }, Evidence::matches('~\b(alumini?um|stainless[ -]+steel|titanium|ceramic)\b(?!\s*(?:band|strap|loop|bracelet|back))~',
            Evidence::texts($attrs, $title, 'Case Material'))));

        $candidates = [];
        foreach (AppleCatalog::WATCH_MODELS as $model => $configurations) {
            $permitted = [$model];
            if (str_starts_with($model, 'ultra')) {
                $permitted[] = 'ultra-unspecified';
            }
            if (str_starts_with($model, 'se')) {
                $permitted[] = 'se-unspecified';
            }
            if (!Evidence::subset($models, $permitted)) {
                continue;
            }
            foreach ($configurations as $size => [$gps, $cellular]) {
                foreach (['gps' => $gps, 'cellular' => $cellular] as $kind => $allowedIds) {
                    if ($allowedIds === '' || !Evidence::subset($sizes, [$size])
                        || !Evidence::subset($ids, explode(' ', $allowedIds)) || !Evidence::subset($networks, [$kind])
                    ) {
                        continue;
                    }
                    $allowed = str_starts_with($model, 'ultra') ? ['titanium'] : ['aluminium'];
                    if (str_starts_with($model, 'series-')) {
                        $number = (int)substr($model, 7);
                        if ($kind === 'cellular' || $number === 2) {
                            $allowed = explode(' ', AppleCatalog::WATCH_CELLULAR_MATERIALS[$number] ?? 'aluminium');
                        }
                    }
                    if (!Evidence::subset($materials, $allowed) || count($materials) > 1
                        || ($materials === [] && count($allowed) > 1)
                    ) {
                        continue;
                    }
                    $material = $materials !== [] && count($allowed) > 1 ? '-' . $materials[0] : '';
                    $candidates[] = "apple-watch-$model-{$size}mm-$kind$material";
                }
            }
        }
        return Evidence::only($candidates);
    }

    public function airpods(array $attrs, string $title): string
    {
        $texts = Evidence::texts($attrs, $title, 'Model', 'Product Line', 'Series', 'MPN', 'Type');
        $component = '~\b(?:replica|clone|counterfeit|fake|single (?:earbud|bud|airpod)|'
            . '(?:left|right)(?:[ -]+(?:side|ear|earbud|bud|airpod))?\s*(?:and|&|\+|/)\s*(?:the\s+)?(?:charging\s+)?case|'
            . '(?:left|right)(?:[ -]+(?:side|ear|earbud|bud|airpod))?\b(?!\s*(?:and|&|\+|/))|'
            . '(?:no|without|missing)\s+(?:charging\s+case|case|buds?|earbuds?)|'
            . '(?:charging\s+case|earbuds?|buds?)\s+only|replacement\s+(?:charging\s+case|earbuds?|case)|'
            . '(?:case|earbud)\s+replacement|(?:no|without)\s+(?:airpods|headphones))\b~';
        if (Evidence::any($component, $texts) || Evidence::any('~\bairpods?\s+pro\s+max\b~', $texts)) {
            return 'unknown';
        }
        $ids = Evidence::identifiers($texts);
        $families = Evidence::matches('~\bairpods?[ -]+(pro|max)\b~', $texts);
        $generations = Evidence::generations($texts);
        foreach ($texts as $text) {
            preg_match_all('~\bairpods?[ -]*(?:(pro|max)\b)?[ (\-]*(\d)(?!\d|\s*(?:gb|pack|pk))~', $text, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $tier, $number]) {
                $families[] = $tier ?: 'base';
                $generations[] = (int)$number;
            }
        }
        $families = Evidence::unique($families);
        $generations = Evidence::unique($generations);
        if (count($families) > 1 || count($generations) > 1 || ($families === [] && $generations === [] && $ids === [])) {
            return 'unknown';
        }
        $usb = Evidence::any('~\b(?:usb[ -]?c|type[ -]?c)\b~', $texts);
        $lightning = Evidence::any('~\blightning\b~', $texts);
        $earbuds = array_diff($ids, AppleCatalog::AIRPODS_CASES);
        if (($usb && $lightning) || ($ids !== [] && $earbuds === [] && !Evidence::any('~\b(?:with|includes?)\b~', $texts))) {
            return 'unknown';
        }
        if ($families === [] && $earbuds === [] && Evidence::any('~\bairpods?\b~', $texts)) {
            $families[] = 'base';
        }
        $anc = Evidence::any('~\b(?:anc|active noise cancel)~', $texts);
        $noAnc = Evidence::any('~\b(?:no|without|non)[ -]+(?:anc|active noise cancel)~', $texts);
        $candidates = [];
        foreach (AppleCatalog::AIRPODS_MODELS as $model => $numbers) {
            $model = (string)$model;
            $parts = explode('-', $model);
            $family = in_array($parts[0], ['pro', 'max'], true) ? $parts[0] : 'base';
            $number = (int)$parts[$family === 'base' ? 0 : 1];
            $isUsb = str_ends_with($model, '-usbc') || str_starts_with($model, '4') || str_starts_with($model, '5')
                || in_array($model, ['max-2', 'pro-3'], true);
            if (!Evidence::subset($families, [$family]) || !Evidence::subset($generations, [$number])
                || !Evidence::subset($earbuds, explode(' ', $numbers)) || ($usb && !$isUsb) || ($lightning && $isUsb)
            ) {
                continue;
            }
            if ($family === 'base' && $number === 4 && (($noAnc && str_ends_with($model, '-anc'))
                || ($anc && !$noAnc && !str_ends_with($model, '-anc')))
            ) {
                continue;
            }
            $candidates[] = 'apple-airpods-' . $model;
        }
        return Evidence::only($candidates);
    }

    public function airtag(array $attrs, string $title): string
    {
        $texts = Evidence::texts($attrs, $title, 'Model', 'MPN', 'Type', 'Product Line');
        if (Evidence::any('~\b(?:bundle|mixed lot|assorted|replica|clone|holder|key[ -]?ring|keychain|protective case|cover|accessor(?:y|ies)|replacement|empty box)\b~', $texts)) {
            return 'unknown';
        }
        $gen = array_merge(Evidence::generations($texts), Evidence::matches(
            '~\bair[ -]?tags?[ -]+([12])\b(?![ -]*(?:pack|pk|pcs|units))~', $texts));
        $ids = Evidence::identifiers($texts);
        foreach ([1 => 'a2187', 2 => 'a2937'] as $generation => $id) {
            if (in_array($id, $ids, true)) {
                $gen[] = $generation;
            }
        }
        if (Evidence::any('~\b(?:second[ -]+generation ultra wideband|u2 chip)\b~', $texts)) {
            $gen[] = 2;
        }
        $counts = [];
        $skuPacks = ['mx532' => [1, 1], 'mx542' => [1, 4], 'mfe94' => [2, 1], 'mfea2' => [2, 4], 'mfea4' => [2, 4]];
        foreach (Evidence::matches('~\b(mx532|mx542|mfe94|mfea2|mfea4)(?:[a-z]{1,3}(?:/a)?)?\b~', $texts) as $sku) {
            [$gen[], $counts[]] = $skuPacks[$sku];
        }
        $words = array_combine(explode(' ', 'one two three four five six seven eight nine ten'), range(1, 10));
        $counts = array_merge($counts,
            Evidence::matches('~\b(?:pack of|lot of|x|×)\s*(\d{1,2})\b~u', $texts),
            array_filter(Evidence::texts($attrs, '', 'Number in Pack', 'Pack Quantity', 'Unit Quantity'), 'ctype_digit')
        );
        foreach (Evidence::matches('~\b(\d{1,2}|one|two|three|four|five|six|seven|eight|nine|ten)[ -]*(?:pack|pk|pcs|pieces|units|air[ -]?tags?)\b~', $texts) as $count) {
            $counts[] = $words[$count] ?? (int)$count;
        }
        if (Evidence::any('~\b(?:single|individual)(?:\s+(?:unit|air[ -]?tag|pack|tracker))?\b~', $texts)) {
            $counts[] = 1;
        }
        $gen = Evidence::unique(array_map('intval', $gen));
        $counts = Evidence::unique(array_map('intval', $counts));
        if (count($gen) !== 1 || !Evidence::subset($gen, [1, 2]) || count($counts) !== 1 || $counts[0] <= 0) {
            return 'unknown';
        }
        return 'apple-airtag-' . Evidence::ordinal($gen[0]) . '-' . $counts[0] . 'pack';
    }

    public function storage(array $attrs, string $title): string
    {
        $fields = Evidence::texts($attrs, '', 'Storage Capacity', 'SSD Capacity', 'Capacity');
        $titles = Evidence::texts($attrs, $title, 'Model');
        $capacities = [];
        foreach (array_merge($fields, $titles) as $text) {
            preg_match_all('~\b(\d{2,4})\s*[/|]\s*\d{2,4}\s*(gb|tb)\b~', $text, $alternatives, PREG_SET_ORDER);
            foreach ($alternatives as [, $amount, $unit]) {
                $capacities[] = (int)$amount . $unit;
            }
            preg_match_all('~(?<![\d.])(\d{1,4})\s*(gb|tb)\b~', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as [$full, $amount, $unit]) {
                if (in_array($text, $titles, true) && (
                    preg_match('~^\s*(?:ram|unified memory)\b~', substr($text, $full[1] + strlen($full[0])))
                    || preg_match('~\b(?:ram|unified memory)\s*[:=]?\s*$~', substr($text, 0, $full[1]))
                    || ($unit[0] === 'gb' && in_array((int)$amount[0], [3, 4, 6, 8, 12], true))
                )) {
                    continue;
                }
                $capacities[] = (int)$amount[0] . $unit[0];
            }
        }
        $capacities = Evidence::unique($capacities);
        return count($capacities) === 1 ? $capacities[0] : '';
    }

    private function connectivity(array $texts, bool $watch = false): array
    {
        $networks = [];
        foreach ($texts as $text) {
            $cellular = preg_match('~\b(?:cellular|cel|lte|5g|4g|3g|mobile data)\b~', $text);
            if ($cellular) {
                $networks[] = 'cellular';
            }
            if ($watch && preg_match('~\bgps[ -]+only\b~', $text)) {
                $networks[] = 'gps';
            } elseif ($watch && !$cellular && preg_match('~\bgps\b~', $text)) {
                $networks[] = 'gps-unspecified';
            }
            if (!$watch && (preg_match('~\bwi[ -]?fi\s+only\b~', $text) || (!$cellular && preg_match('~\bwi[ -]?fi\b~', $text)))) {
                $networks[] = 'wifi';
            }
        }
        return Evidence::unique($networks);
    }
}
