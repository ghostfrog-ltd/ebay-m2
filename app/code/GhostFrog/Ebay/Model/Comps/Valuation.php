<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Comps;

use Magento\Framework\App\ResourceConnection;

final class Valuation
{
    private const SOURCE_MINIMUMS = [
        'ebay-apple' => 10,
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
        $group = $listing === null ? null : $this->eligibility->group($listing);
        if ($group === null) {
            return null;
        }

        [$key, $grade] = $group;
        $db = $this->resource->getConnection();
        $select = $db->select()->from($this->resource->getTableName(Index::TABLE), [
            'median_final_price', 'samples', 'computed_at',
        ])->where('model_key = ?', $key)->where('condition_grade = ?', $grade);
        $comp = $db->fetchRow($select) ?: [];
        $price = (float)$listing['price_current'];
        $count = (int)($comp['samples'] ?? 0);
        $median = (float)($comp['median_final_price'] ?? 0);
        $source = $listing['source_domain'] ?: $listing['source'];
        $minimum = self::SOURCE_MINIMUMS[$source] ?? 15;
        $valuation = [
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
