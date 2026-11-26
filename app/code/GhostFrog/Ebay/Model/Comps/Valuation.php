<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Comps;

use Magento\Framework\App\ResourceConnection;

final class Valuation
{
    private const SOURCE_MINIMUMS = [
        'ebay-apple' => 10,
        'ebay-ipod' => 5,
        'ebay-rc' => 5,
        'ebay-samsung' => 5,
        'ebay-graphics-cards' => 5,
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Listings $listings,
        private readonly Eligibility $eligibility,
        private readonly Pricing $pricing
    ) {
    }

    public function forListing(int $id): ?array
    {
        $listing = $this->listings->live($id);
        return $listing === null ? null : ($this->forLiveBatch([$listing])[$id] ?? null);
    }

    public function forLiveBatch(array $listings, ?array $cohorts = null): array
    {
        $groups = [];
        $keys = [];
        foreach ($listings as $listing) {
            $group = $this->eligibility->group($listing);
            if ($group !== null && ($cohorts === null || isset($cohorts[$group[0]][$group[1]]))) {
                $groups[$listing['id']] = $group;
                $keys[$group[0]] = true;
            }
        }
        if ($groups === []) {
            return [];
        }

        $db = $this->resource->getConnection();
        $select = $db->select()->from($this->resource->getTableName(Index::TABLE), [
            'model_key', 'condition_grade', 'median_final_price', 'samples', 'computed_at',
        ])->where('model_key IN (?)', array_keys($keys));
        $comps = [];
        foreach ($db->fetchAll($select) as $comp) {
            $comps[$comp['model_key']][$comp['condition_grade']] = $comp;
        }

        $values = [];
        foreach ($listings as $listing) {
            $id = $listing['id'];
            if (isset($groups[$id])) {
                [$key, $grade] = $groups[$id];
                $values[$id] = $this->calculate($listing, $key, $grade, $comps[$key][$grade] ?? []);
            }
        }
        return $values;
    }

    private function calculate(array $listing, string $key, string $grade, array $comp): array
    {
        $price = (float)$listing['price_current'];
        $count = (int)($comp['samples'] ?? 0);
        $median = (float)($comp['median_final_price'] ?? 0);
        $source = $listing['source_domain'] ?: $listing['source'];
        $minimum = self::SOURCE_MINIMUMS[$source] ?? 15;
        $valuation = [
            'model_key' => $key,
            'condition_grade' => $grade,
            'comp_count' => $count,
            'minimum' => $minimum,
            'sold_median' => $median > 0 ? $median : null,
            'computed_at' => $comp['computed_at'] ?? null,
        ];

        if ($count < $minimum || $median <= 0) {
            return $valuation + ['signal' => __('Insufficient sold comps')];
        }

        $pricing = $this->pricing->calculate($median, $price);
        return $valuation + $pricing + [
            'signal' => $pricing['meets_target'] ? __('Meets profit target') : __('Below profit target'),
        ];
    }
}
