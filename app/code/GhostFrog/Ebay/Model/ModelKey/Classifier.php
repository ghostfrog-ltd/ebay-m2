<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey;

use GhostFrog\Ebay\Model\ModelKey\Rules\Bikes;
use GhostFrog\Ebay\Model\ModelKey\Rules\Cameras;
use GhostFrog\Ebay\Model\ModelKey\Rules\Components;
use GhostFrog\Ebay\Model\ModelKey\Rules\ConsoleAccessories;
use GhostFrog\Ebay\Model\ModelKey\Rules\Consoles;
use GhostFrog\Ebay\Model\ModelKey\Rules\Headphones;
use GhostFrog\Ebay\Model\ModelKey\Rules\HondaMsx125;
use GhostFrog\Ebay\Model\ModelKey\Rules\HondaNc750;
use GhostFrog\Ebay\Model\ModelKey\Rules\Ipod;
use GhostFrog\Ebay\Model\ModelKey\Rules\Lego;
use GhostFrog\Ebay\Model\ModelKey\Rules\MiniCooper;
use GhostFrog\Ebay\Model\ModelKey\Rules\Motors;
use GhostFrog\Ebay\Model\ModelKey\Rules\Pokemon;
use GhostFrog\Ebay\Model\ModelKey\Rules\RcCars;
use GhostFrog\Ebay\Model\ModelKey\Rules\RetroPc;
use GhostFrog\Ebay\Model\ModelKey\Rules\Samsung;
use GhostFrog\Ebay\Model\ModelKey\Rules\Tools;
use GhostFrog\Ebay\Model\ModelKey\Rules\Watches;

final class Classifier
{
    private const CONSOLES = 'ebay-consoles';

    /** Columns a classification may set besides the key. Sources without item types leave them alone. */
    public const DETAIL_COLUMNS = ['item_type', 'key_source', 'brand', 'is_accessory'];

    public function __construct(
        private readonly AppleMac $mac,
        private readonly AppleDevices $devices,
        private readonly ?BrandAliases $aliases = null
    ) {
    }

    /** Key for a stored listing row, from its title, raw_attrs (JSON or decoded) and enrichment columns. */
    public function forListing(string $source, array $listing): string
    {
        return $this->columnsForListing($source, $listing)['model_key'];
    }

    /**
     * The listing columns a classification decides: model_key always, and for sources with item types
     * (ebay-rc) also item_type, key_source and the canonical brand.
     *
     * @return array<string, ?string>
     */
    public function columnsForListing(string $source, array $listing): array
    {
        $attrs = $listing['raw_attrs'] ?? [];
        if (is_string($attrs)) {
            $attrs = json_decode($attrs, true);
        }
        $fields = array_intersect_key($listing, array_flip(['brand', 'model_name', 'storage_gb', 'colour', 'epid']));
        return $this->columns($source, (string)($listing['title'] ?? ''), (is_array($attrs) ? $attrs : []) + $fields);
    }

    public function key(string $source, string $title, array $attrs): string
    {
        return $this->columns($source, $title, $attrs)['model_key'];
    }

    /** @return array<string, ?string> model_key, plus the DETAIL_COLUMNS the source's rules decide */
    public function columns(string $source, string $title, array $attrs): array
    {
        if (strtolower(trim($source)) !== RcCars::SOURCE) {
            $key = $this->plainKey($source, $title, $attrs);
            if (strtolower(trim($source)) !== self::CONSOLES) {
                return ['model_key' => $key];
            }
            return ['model_key' => $key, 'is_accessory' => ConsoleAccessories::isAccessoryKey($key) ? '1' : '0'];
        }
        // RC reads the item specifics exactly as eBay returned them. The brand column holds the name this
        // classifier chose, so feeding it back in would make the answer depend on the previous run.
        $found = RcCars::classify($attrs, $title, $this->aliases);
        $key = mb_strtolower(trim($found['model_key']));
        $found['model_key'] = $key === '' || strlen($key) > 255 ? 'unknown' : $key;
        if ($found['brand'] === null) {
            unset($found['brand']);
        }
        return $found;
    }

    private function plainKey(string $source, string $title, array $attrs): string
    {
        if (trim($title) === '') {
            return 'unknown';
        }
        $source = strtolower(trim($source));
        // Packaging and empty boxes are not the product. Faulty and repair listings keep their identity:
        // comps eligibility values them only against other faulty sales.
        // For consoles a case or charger is an accessory with a key of its own.
        $notTheItem = $source === self::CONSOLES ? 'box only|empty box|packaging only'
            : 'box only|empty box|packaging only|case only|charger only';
        if (preg_match('~\b(?:' . $notTheItem . ')\b~i', $title)) {
            return 'unknown';
        }
        foreach (['Brand' => 'brand', 'Model' => 'model_name', 'Colour' => 'colour', 'ePID' => 'epid'] as $key => $column) {
            if (empty($attrs[$key]) && !empty($attrs[$column])) {
                $attrs[$key] = $attrs[$column];
            }
        }
        $storageFields = ['Storage Capacity'];
        if ($source === 'ebay-apple' || $source === 'ebay-ipod') {
            array_push($storageFields, 'SSD Capacity', 'Hard Drive Capacity', 'Capacity');
        }
        if (!empty($attrs['storage_gb']) && !array_intersect_key(array_filter($attrs), array_flip($storageFields))) {
            $attrs['Storage Capacity'] = $attrs['storage_gb'] . ' GB';
        }

        $key = match ($source) {
            'ebay-apple' => $this->apple($attrs, Evidence::texts([], $title)[0]),
            'ebay-ipod' => Ipod::key($attrs, $title),
            'ebay-actioncams' => Cameras::cameraDroneModelKey($attrs, $title),
            'ebay-camera-lenses' => Components::cameraLensModelKey($attrs, $title),
            'ebay-consoles' => Consoles::consoleOrGameModelKey($attrs, $title),
            'ebay-cpus' => Components::cpuModelKey($attrs, $title),
            'ebay-graphics-cards' => Components::graphicsCardModelKey($attrs, $title),
            'ebay-headphones' => Headphones::headphonesModelKey($attrs, $title),
            'ebay-honda-msx125' => HondaMsx125::hondaMsx125ModelKey($attrs, $title),
            'ebay-honda-nc750' => HondaNc750::hondaNc750ModelKey($attrs, $title),
            'ebay-industrial-automation' => Components::industrialAutomationModelKey($attrs, $title),
            'ebay-lego' => Lego::legoModelKey($attrs, $title),
            'ebay-mini-cooper' => MiniCooper::miniCooperModelKey($attrs, $title),
            'ebay-motherboards' => Components::motherboardModelKey($attrs, $title),
            'ebay-motors' => Motors::motorsModelKey($attrs, $title),
            'ebay-pokemon' => Pokemon::pokemonModelKey($attrs, $title),
            'ebay-retro-pc' => RetroPc::key($attrs, $title),
            'ebay-samsung' => Samsung::samsungModelKey($attrs, $title),
            'ebay-tools' => Tools::toolsModelKey($attrs, $title),
            'ebay-watches' => Watches::watchModelKey($attrs, $title),
            'motomine' => $this->motomine($attrs, $title),
            default => null,
        };
        $key = mb_strtolower(trim((string)$key));
        return $key === '' || strlen($key) > 255 ? 'unknown' : $key;
    }

    private function motomine(array $attrs, string $title): string
    {
        $key = Bikes::bikeModelKey($attrs, $title);
        // MotoMine is a seller-specific parts source. Keep those keys out of whole-bike comps.
        return $key === 'unknown' ? $key : 'motomine-' . $key;
    }

    private function apple(array $attrs, string $title): string
    {
        $texts = Evidence::texts($attrs, $title, 'Model', 'Product Line', 'Series', 'Type',
            'Processor', 'Chipset Model', 'CPU', 'MPN', 'Browse condition', 'Trading ConditionDisplayName');
        $junk = '~\b(?:case for|cover for|screen protector|tempered glass|replacement (?:screen|keyboard|battery)|'
            . 'screen assembly|lcd assembly|digitizer|logic ?board|motherboard|housing|back glass|front glass|'
            . 'sim tray|flex cable|(?:charger|cable|case|box) only|empty box|dummy phone|display phone|'
            . 'activation lock(?:ed)?|icloud locked|mdm locked|job lot)\b~';
        if (Evidence::any($junk, $texts)) {
            return 'unknown';
        }
        $family = $this->family($title);
        if ($family === '') {
            $families = array_filter(array_map($this->family(...),
                Evidence::texts($attrs, '', 'Product Line', 'Series', 'Model', 'Type')));
            $family = Evidence::only(Evidence::unique($families));
        }
        if (str_starts_with($family, 'mac') || str_starts_with($family, 'imac')) {
            return $this->mac->key($attrs, $title, $family);
        }
        return match ($family) {
            'iphone' => $this->devices->iphone($attrs, $title),
            'ipad' => $this->devices->ipad($attrs, $title),
            'watch' => $this->devices->watch($attrs, $title),
            'airpods' => $this->devices->airpods($attrs, $title),
            'airtag' => $this->devices->airtag($attrs, $title),
            'apple-tv' => $this->appleTv($texts, $attrs, $title),
            'homepod' => $this->homepod($texts),
            'ipod' => Ipod::key($attrs, $title),
            default => 'unknown',
        };
    }

    private function family(string $text): string
    {
        if (!preg_match('~\b(airpods?|iphone|ipad|(?:apple\s+)?watch|air[ -]?tags?|apple[ -]+tv|homepod|ipod|'
            . 'macbook(?:[ -]+(?:air|pro|neo))?|imac(?:[ -]+pro)?|mac[ -]*(?:mini|studio|pro))\b~', $text, $match)
        ) {
            return '';
        }
        $name = $match[1];
        return match (true) {
            str_starts_with($name, 'airpod') => 'airpods',
            str_starts_with(str_replace([' ', '-'], '', $name), 'airtag') => 'airtag',
            str_ends_with($name, 'watch') => 'watch',
            default => AppleMac::line($name) ?: str_replace(' ', '-', $name),
        };
    }

    private function appleTv(array $texts, array $attrs, string $title): string
    {
        $model = Evidence::any('~\b4k\b~', $texts) ? 'tv-4k' : (Evidence::any('~\bhd\b~', $texts) ? 'tv-hd' : '');
        if ($model === '') {
            return 'unknown';
        }
        return implode('-', array_filter(['apple', $model, $this->devices->storage($attrs, $title)]));
    }

    private function homepod(array $texts): string
    {
        $model = Evidence::any('~\bmini\b~', $texts) ? 'homepod-mini' : 'homepod';
        return 'apple-' . $model . (Evidence::any('~\b(?:2nd|second|2023)\b~', $texts) ? '-2nd-gen' : '');
    }
}
