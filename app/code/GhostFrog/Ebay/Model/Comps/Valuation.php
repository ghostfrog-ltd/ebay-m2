<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Comps;

use Magento\Framework\App\ResourceConnection;

final class Valuation
{
    /** Used for listings with no source row; each source sets its own minimum in Sources admin. */
    public const DEFAULT_MINIMUM = 15;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Listings $listings,
        private readonly Eligibility $eligibility,
        private readonly Pricing $pricing,
        private readonly ResaleBasis $resaleBasis
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
            'bin_median_final_price', 'bin_samples',
        ])->where('model_key IN (?)', array_keys($keys));
        $comps = [];
        foreach ($db->fetchAll($select) as $comp) {
            $comps[$comp['model_key']][$comp['condition_grade']] = $comp;
        }

        $values = [];
        $basis = $this->resaleBasis->current();
        foreach ($listings as $listing) {
            $id = $listing['id'];
            if (isset($groups[$id])) {
                [$key, $grade] = $groups[$id];
                $values[$id] = $this->calculate($listing, $key, $grade, $comps[$key][$grade] ?? [], $basis);
                if ($grade === Eligibility::FAULTY_GRADE) {
                    // What a working one fetches, for judging repair upside. It plays no part in the valuation.
                    $working = (float)($comps[$key]['B']['median_final_price'] ?? 0);
                    $values[$id]['working_median'] = $working > 0 ? $working : null;
                }
            }
        }
        return $values;
    }

    private function calculate(array $listing, string $key, string $grade, array $comp, string $basis): array
    {
        $price = (float)$listing['price_current'];
        $count = (int)($comp['samples'] ?? 0);
        // The sold-comp minimum applies to all sales; the resale median may come from Buy It Now sales only.
        [$median, $usedBasis] = ResaleBasis::median($comp, $basis);
        if ((float)($comp['median_final_price'] ?? 0) <= 0) {
            $median = 0.0;
        }
        $minimum = (int)($listing['source_min_comps'] ?? self::DEFAULT_MINIMUM);
        $valuation = [
            'model_key' => $key,
            'condition_grade' => $grade,
            'comp_count' => $count,
            'minimum' => $minimum,
            'sold_median' => $median > 0 ? $median : null,
            'resale_basis' => $usedBasis,
            'computed_at' => $comp['computed_at'] ?? null,
        ];

        if ($count < $minimum || $median <= 0) {
            return $valuation + ['signal' => __('Insufficient sold comps')];
        }

        $pricing = $this->pricing->calculate($median, $price, $listing);
        return $valuation + $pricing + [
            'signal' => $pricing['meets_target'] ? __('Meets profit target') : __('Below profit target'),
        ];
    }
}
