<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Maps the many ways sellers write an RC brand onto one name.
 *
 * The map is one brand per line, "Canonical: alias, alias". Matching is on the whole brand string and
 * ignores case, so "TAMIYA" and "Tekno RC" merge while "Thunder Tiger" never loses a word. A brand that is
 * not listed is kept as the seller wrote it. The map is edited in Stores > Configuration > GhostFrog eBay >
 * RC Classification; the default there is a copy of DEFAULTS, which is used when the setting is empty.
 *
 * Model keys are built from the canonical name, so after changing the map run
 * `bin/magento ghostfrog:ebay:rc:reclassify` to bring stored listings in line.
 */
final class BrandAliases
{
    public const CONFIG_PATH = 'ghostfrog_ebay/rc/brand_aliases';

    /** The one value every "no brand" spelling becomes. */
    public const UNBRANDED = 'Unbranded';

    public const DEFAULTS = <<<'MAP'
        Unbranded: No Brand, NoBrand, Non-Branded, Not Branded, Unbranded/Generic, Generic, N/A, NA, None, Unknown, Does not apply, Not specified, Other, Custom, Various, - Sans marque/Générique -, Sans marque, Markenlos, Senza marca, Sin marca
        Tamiya: Tamiya RC, Tamiya TRF
        Kyosho: Kyosho RC
        Yokomo: Team Yokomo
        Team Associated: Associated, Associated Electrics, Team Associated RC, Team Associated (AE), AE
        Losi: Team Losi, Team Losi Racing, TLR
        Schumacher: Schumacher Racing, Schumacher RC
        Xray: X-Ray, Team Xray
        Mugen Seiki: Mugen, Mugen Seiki Racing
        Tekno: Tekno RC, TeknoRC
        Serpent: Serpent RC, Team Serpent
        Infinity: Team Infinity
        Awesomatix
        Futaba: Futaba Denshi, Futaba RC
        Sanwa: Sanwa Denshi, Airtronics
        Hobbywing: Hobby Wing, HobbyWing Technology
        LRP: LRP Electronic
        Tekin
        Team Orion: Orion
        Savox: Savöx
        Spektrum: Spektrum RC
        FlySky: Fly Sky, Fly-Sky
        Muchmore: Much More, Muchmore Racing
        HPI: HPI Racing, Hobby Products International
        Traxxas
        Arrma: ARRMA RC
        Thunder Tiger: ThunderTiger, Thunder Tiger RC
        HoBao: Ho Bao, HoBao Racing, Hobao Hyper
        Hot Bodies: HotBodies, HB Racing
        Team Durango: Durango
        FTX
        Maverick: Maverick RC
        Axial: Axial Racing
        Carisma: Carisma RC
        Team Magic
        Corally: Team Corally
        Nikko
        Marui: Tokyo Marui
        Novarossi: Nova Rossi
        O.S.: OS, O.S, OS Engines, O.S. Engines, O.S. Engine, OS Engine, OS MAX, O.S. MAX
        Picco
        Sirio
        REDS: Reds Racing
        Super Tigre: SuperTigre, Super Tiger
        Dynamite: Dynamite RC
        MAP;

    /** @var array<string, string>|null lowercase alias => canonical name */
    private ?array $map = null;

    public function __construct(private readonly ?ScopeConfigInterface $config = null)
    {
    }

    /** The canonical name for a brand, or the trimmed brand itself when the map does not list it. */
    public function canonical(string $brand): string
    {
        $brand = trim(preg_replace('~\s+~u', ' ', $brand) ?? '');
        if ($brand === '') {
            return '';
        }
        $this->map ??= self::parse((string)($this->config?->getValue(self::CONFIG_PATH) ?? '') ?: self::DEFAULTS);
        return $this->map[mb_strtolower($brand)] ?? $brand;
    }

    /** @return array<string, string> lowercase alias => canonical name; each canonical name maps to itself */
    public static function parse(string $text): array
    {
        $map = [];
        foreach (preg_split('~\R~u', $text) ?: [] as $line) {
            [$canonical, $aliases] = array_pad(explode(':', $line, 2), 2, '');
            $canonical = trim($canonical);
            if ($canonical === '' || str_starts_with($canonical, '#')) {
                continue;
            }
            foreach (array_merge([$canonical], explode(',', $aliases)) as $alias) {
                $alias = mb_strtolower(trim(preg_replace('~\s+~u', ' ', $alias) ?? ''));
                if ($alias !== '') {
                    $map[$alias] ??= $canonical;
                }
            }
        }
        return $map;
    }
}
