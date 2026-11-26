<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey;

use GhostFrog\Ebay\Model\ModelKey\Rules\Bikes;
use GhostFrog\Ebay\Model\ModelKey\Rules\Cameras;
use GhostFrog\Ebay\Model\ModelKey\Rules\Components;
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
    public function __construct(
        private readonly AppleMac $mac,
        private readonly AppleDevices $devices
    ) {
    }

    public function key(string $source, string $title, array $attrs): string
    {
        if (trim($title) === '') {
            return 'unknown';
        }
        $source = strtolower(trim($source));
        // Packaging, empty boxes and explicit repair listings are unsafe product identities.
        // RC keeps faulty listings identified; comps eligibility still excludes them.
        if ($source !== 'ebay-rc' && preg_match('~\b(?:box only|empty box|packaging only|case only|charger only|'
            . 'for parts|parts only|parts (?:or|and) repair|spares (?:or|and) repair|repair only)\b~i', $title)
        ) {
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
            'ebay-rc' => RcCars::key($attrs, $title),
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
            . 'for parts|spares (?:and|or) repair|repair only|activation lock(?:ed)?|icloud locked|mdm locked|job lot)\b~';
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
