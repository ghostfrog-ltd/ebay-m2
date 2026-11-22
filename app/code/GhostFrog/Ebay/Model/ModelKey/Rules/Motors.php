<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey\Rules;

final class Motors extends Rule
{
    private const MAKE_ALIASES = [
        'citroa n' => 'citroen',
        'citroen' => 'citroen',
        'landrover' => 'land-rover',
        'land rover' => 'land-rover',
        'range rover' => 'land-rover',
        'mercedes' => 'mercedes-benz',
        'mercedes benz' => 'mercedes-benz',
        'mercedesbenz' => 'mercedes-benz',
        'mini' => 'mini',
        'skoda' => 'skoda',
        'a koda' => 'skoda',
        'toyoya' => 'toyota',
        'volgswagen' => 'volkswagen',
        'vw' => 'volkswagen',
        'vw split screen' => 'volkswagen',
        'vauxhall opel' => 'vauxhall',
        'vauxhall' => 'vauxhall',
        'vauxhaul' => 'vauxhall',
    ];

    private const KNOWN_MAKES = [
        'abarth',
        'alfa-romeo',
        'aston-martin',
        'audi',
        'austin',
        'bentley',
        'bmw',
        'chevrolet',
        'chrysler',
        'citroen',
        'dacia',
        'dodge',
        'ds',
        'fiat',
        'ford',
        'honda',
        'hummer',
        'hyundai',
        'jaguar',
        'jeep',
        'kia',
        'land-rover',
        'lexus',
        'lotus',
        'maserati',
        'mazda',
        'mercedes-benz',
        'mg',
        'mini',
        'mitsubishi',
        'nissan',
        'peugeot',
        'porsche',
        'reliant',
        'renault',
        'rover',
        'saab',
        'seat',
        'skoda',
        'smart',
        'ssangyong',
        'subaru',
        'suzuki',
        'tesla',
        'toyota',
        'trabant',
        'vauxhall',
        'volkswagen',
        'volvo',
    ];

    private const PART_TERMS = [
        'alloy wheel',
        'wheels and tyres',
        'wheel & tyre',
        'tyre',
        'tyres',
        'headlight',
        'headlamp',
        'rear light',
        'tail light',
        'bumper',
        'bonnet',
        'bootlid',
        'tailgate',
        'door handle',
        'grille',
        'radiator',
        'intercooler',
        'turbo',
        'exhaust',
        'brake disc',
        'brake pad',
        'caliper',
        'shock absorber',
        'strut',
        'spring',
        'seat',
        'steering wheel',
        'airbag',
        'dashboard',
        'engine control unit',
        'ecu',
        'cover headlamp',
    ];

    private const NON_VEHICLE_TERMS = [
        'diecast',
        'die-cast',
        'model car',
        'model bike',
        'hot wheels',
        'matchbox',
        'corgi',
        'lego',
        'technic',
        'poster',
        'brochure',
        'owners manual',
        'owner s manual',
        'workshop manual',
        'haynes manual',
        'number plate',
        'private plate',
        'keyring',
        't-shirt',
        't shirt',
        'fishing',
        'carp',
        'daiwa',
        'trakker',
        'prologic',
        'nash',
    ];

    private const MODEL_STOP_WORDS = [
        'amg',
        'auto',
        'bluehdi',
        'diesel',
        'edition',
        'estate',
        'hatchback',
        'line',
        'luxury',
        'm',
        'm-sport',
        'manual',
        'msport',
        'petrol',
        'premium',
        'puretech',
        'quattro',
        's-line',
        'saloon',
        'sline',
        'sport',
        'tdi',
        'tfsi',
        'xdrive',
    ];

    private static function clean($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            return implode(' ', array_map(static fn($item) => self::clean($item), self::iterate($value)));
        }
        return mb_strtolower(trim(self::stringify($value)));
    }

    private static function spaced(string $value): string
    {
        return trim(self::replacePattern('[^a-z0-9]+', ' ', mb_strtolower($value)));
    }

    private static function slug(string $value): string
    {
        return trim(self::replacePattern('[^a-z0-9]+', '-', self::spaced($value)), '-');
    }

    private static function blob(array $attrs, string $title): string
    {
        $parts = [$title];
        array_push($parts, ...array_map(static fn($value) => self::stringify($value), array_values(array_filter(array_values($attrs), static fn($value) => ($value !== null)))));
        return self::spaced(implode(' ', $parts));
    }

    private static function normaliseMake($raw, string $title = ''): string
    {
        $cleaned = self::spaced(self::clean($raw));
        if (in_array($cleaned, ['other', 'unbranded', 'does not apply', 'not supplied', 'n a'], true)) {
            $cleaned = '';
        }
        if ($cleaned) {
            $cleaned = (self::MAKE_ALIASES[$cleaned] ?? $cleaned);
            $cleaned = self::slug($cleaned);
            if (self::contains(self::KNOWN_MAKES, $cleaned)) {
                return $cleaned;
            }
            foreach (self::longestFirst(self::KNOWN_MAKES) as $make) {
                $makeText = str_replace('-', ' ', $make);
                if (self::search('\\b' . preg_quote($makeText) . '\\b', str_replace('-', ' ', $cleaned))) {
                    return $make;
                }
            }
        }
        $titleText = self::spaced($title);
        foreach (self::longestFirst(self::KNOWN_MAKES) as $make) {
            if (self::search('\\b' . preg_quote(str_replace('-', ' ', $make)) . '\\b', $titleText)) {
                return $make;
            }
        }
        return '';
    }

    private static function extractMake(array $attrs, string $title): string
    {
        foreach (['Manufacturer', 'Make', 'Brand'] as $key) {
            $make = self::normaliseMake(($attrs[$key] ?? null), $title);
            if ($make) {
                return $make;
            }
        }
        return self::normaliseMake('', $title);
    }

    private static function extractYear(array $attrs, string $title): string
    {
        foreach ([
            self::clean(($attrs['Year'] ?? null)),
            self::clean(($attrs['Year Manufactured'] ?? null)),
            self::clean(($attrs['Date of 1st Registration'] ?? null)),
            self::clean($title),
        ] as $value) {
            $match = self::search('\\b(19\\d{2}|20[0-2]\\d)\\b', $value);
            if ($match) {
                return $match->group(1);
            }
            $dateMatch = self::search('\\b(19\\d{2}|20[0-2]\\d)\\d{4}\\b', $value);
            if ($dateMatch) {
                return $dateMatch->group(1);
            }
            $ukDateMatch = self::search('\\b\\d{4}(19\\d{2}|20[0-2]\\d)\\b', $value);
            if ($ukDateMatch) {
                return $ukDateMatch->group(1);
            }
        }
        return '';
    }

    private static function extractEngine(array $attrs, string $title): string
    {
        $text = self::spaced(((implode(' ', array_map(static fn($key) => self::clean(($attrs[$key] ?? null)), ['Engine Size', 'Engine', 'Engine Number'])) . ' ') . $title));
        $ccMatch = self::search('\\b(\\d{3,4})\\s?cc\\b', $text);
        if ($ccMatch) {
            return $ccMatch->group(1) . 'cc';
        }
        $litreMatch = self::search('\\b([0-5])[\\s.]([0-9])\\s?(?:l|ltr|litre)?\\b', $text);
        if ($litreMatch) {
            return $litreMatch->group(1) . '.' . $litreMatch->group(2) . 'l';
        }
        return '';
    }

    private static function extractFuel(array $attrs, string $title): string
    {
        $text = self::spaced(implode(' ', [
            self::clean(($attrs['Fuel Type'] ?? null)),
            self::clean(($attrs['Fuel'] ?? null)),
            $title,
        ]));
        $fuelMap = [
            ['diesel electric', 'diesel-hybrid'],
            ['petrol electric', 'petrol-hybrid'],
            ['petrol mhev', 'petrol-mhev'],
            ['diesel mhev', 'diesel-mhev'],
            ['phev', 'phev'],
            ['hybrid', 'hybrid'],
            ['electricity', 'electric'],
            ['electric', 'electric'],
            ['diesel', 'diesel'],
            ['petrol', 'petrol'],
            ['lpg', 'lpg'],
        ];
        foreach ($fuelMap as [$needle, $token]) {
            if (str_contains($text, $needle)) {
                return $token;
            }
        }
        return '';
    }

    private static function extractTransmission(array $attrs, string $title): string
    {
        $text = self::spaced(self::clean(($attrs['Transmission'] ?? null)) . ' ' . $title);
        if ((str_contains($text, 'automatic')) || (str_contains($text, 'auto')) || (str_contains($text, 'dsg'))) {
            return 'automatic';
        }
        if (str_contains($text, 'manual')) {
            return 'manual';
        }
        return '';
    }

    private static function extractBody(array $attrs, string $title): string
    {
        $text = self::spaced(self::clean(($attrs['Body Type'] ?? null)) . ' ' . self::clean(($attrs['Car Type'] ?? null)) . ' ' . $title);
        $bodyMap = [
            ['campervan', 'campervan'],
            ['convertible', 'convertible'],
            ['cabriolet', 'convertible'],
            ['roadster', 'roadster'],
            ['coupe', 'coupe'],
            ['estate', 'estate'],
            ['tourer', 'estate'],
            ['hatchback', 'hatchback'],
            ['saloon', 'saloon'],
            ['suv', 'suv'],
            ['4x4', 'suv'],
            ['mpv', 'mpv'],
            ['van', 'van'],
            ['pickup', 'pickup'],
            ['pick up', 'pickup'],
        ];
        foreach ($bodyMap as [$needle, $token]) {
            if (str_contains($text, $needle)) {
                return $token;
            }
        }
        return '';
    }

    private static function extractDrive(array $attrs, string $title): string
    {
        $text = self::spaced(self::clean(($attrs['Drivetrain'] ?? null)) . ' ' . $title);
        if ((str_contains($text, 'quattro')) || (str_contains($text, '4wd')) || (str_contains($text, '4x4')) || (str_contains($text, 'awd')) || (str_contains($text, 'xdrive'))) {
            return 'awd';
        }
        if (str_contains($text, 'rwd')) {
            return 'rwd';
        }
        if (str_contains($text, 'fwd')) {
            return 'fwd';
        }
        return '';
    }

    private static function extractModel(array $attrs, string $make, string $title): string
    {
        $raw = (self::clean(($attrs['Model'] ?? null)) ?: self::clean(($attrs['Variant'] ?? null)));
        if (!$raw || (in_array($raw, ['not supplied', 'does not apply', 'n a', '.'], true))) {
            $raw = $title;
        }
        $text = self::spaced($raw);
        if ($make) {
            $text = self::replacePattern('\\b' . preg_quote(str_replace('-', ' ', $make)) . '\\b', ' ', $text);
        }
        $year = self::extractYear($attrs, $title);
        if ($year) {
            $text = self::replacePattern('\\b' . $year . '\\b', ' ', $text);
        }
        $tokens = array_values(array_filter(self::words($text), static fn($token) => ($token && (!self::contains(self::MODEL_STOP_WORDS, $token)))));
        if (!$tokens) {
            return '';
        }
        $out = [];
        foreach ($tokens as $token) {
            if (self::contains(self::MODEL_STOP_WORDS, $token)) {
                break;
            }
            $out[] = $token;
            if (self::any(self::iterate($token), static fn($ch) => self::isDigit($ch)) && (self::length($out) >= 2)) {
                break;
            }
            if (self::length($out) >= 3) {
                break;
            }
        }
        return implode('', $out);
    }

    private static function isVehicleListing(array $attrs, string $title, string $make): bool
    {
        $text = self::blob($attrs, $title);
        if (self::any(self::NON_VEHICLE_TERMS, static fn($term) => (self::contains($text, $term)))) {
            return false;
        }
        if (self::any(self::PART_TERMS, static fn($term) => (self::contains($text, $term)))) {
            return false;
        }
        if (!$make) {
            return false;
        }
        return true;
    }

    public static function motorsModelKey(array $attrs, string $title = ''): ?string
    {
        $safeAttrs = ($attrs ?: []);
        $make = self::extractMake($safeAttrs, $title);
        if (!self::isVehicleListing($safeAttrs, $title, $make)) {
            return self::UNKNOWN_KEY;
        }
        $model = self::extractModel($safeAttrs, $make, $title);
        if (!$model) {
            return self::UNKNOWN_KEY;
        }
        $parts = ['vehicle', $make, $model];
        foreach ([
            self::extractEngine($safeAttrs, $title),
            self::extractFuel($safeAttrs, $title),
            self::extractTransmission($safeAttrs, $title),
            self::extractDrive($safeAttrs, $title),
            self::extractBody($safeAttrs, $title),
            self::extractYear($safeAttrs, $title),
        ] as $value) {
            if ($value && (!in_array($value, $parts, true))) {
                $parts[] = $value;
            }
        }
        return implode('-', $parts);
    }
}
