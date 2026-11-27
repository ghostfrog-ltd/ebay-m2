<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Service;

use GhostFrog\Ebay\Api\ListingLookupInterface;
use GhostFrog\Ebay\Model\Comps\Eligibility;
use GhostFrog\Ebay\Model\Comps\Index;
use GhostFrog\Ebay\Model\Comps\Listings;
use GhostFrog\Ebay\Model\Comps\Pricing;
use GhostFrog\Ebay\Model\Comps\ResaleBasis;
use Magento\Framework\App\ResourceConnection;

final class ListingLookup implements ListingLookupInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Listings $listings,
        private readonly Eligibility $eligibility,
        private readonly Pricing $pricing,
        private readonly ResaleBasis $resaleBasis
    ) {
    }

    public function byItemIds(array $itemIds): array
    {
        $itemIds = array_values(array_filter(array_map('strval', $itemIds), 'ctype_digit'));
        if ($itemIds === []) {
            return [];
        }
        $rows = $this->listings->byItemIds($itemIds);
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['id']] = $this->eligibility->group($row);
        }
        $keys = array_unique(array_map(static fn(array $group) => $group[0], array_filter($groups)));
        $comps = [];
        if ($keys !== []) {
            $db = $this->resource->getConnection();
            $select = $db->select()->from($this->resource->getTableName(Index::TABLE), [
                'model_key', 'condition_grade', 'median_final_price', 'samples', 'computed_at',
                'bin_median_final_price', 'bin_samples',
            ])->where('model_key IN (?)', $keys);
            foreach ($db->fetchAll($select) as $comp) {
                $comps[$comp['model_key']][$comp['condition_grade']] = $comp;
            }
        }

        $basis = $this->resaleBasis->current();
        $result = [];
        foreach ($rows as $row) {
            $itemId = Listings::itemId((string)$row['external_id']);
            $group = $groups[$row['id']] ?? null;
            $comp = $group ? ($comps[$group[0]][$group[1]] ?? []) : [];
            [$median, $usedBasis] = ResaleBasis::median($comp, $basis);
            $result[$itemId] = [
                'listing_id' => (int)$row['id'],
                'item_id' => $itemId,
                'title' => (string)$row['title'],
                'source' => (string)(($row['source_domain'] ?? null) ?: $row['source']),
                'status' => (string)$row['status'],
                'sale_type' => $row['sale_type'],
                'best_offer' => (bool)$row['best_offer'],
                'model_key' => $group[0] ?? ($row['model_key'] ?: null),
                'item_type' => $row['item_type'] ?: null,
                'condition_grade' => $group[1] ?? null,
                'text_grade' => $row['text_grade'] ?? null,
                'visual_grade' => $row['visual_grade'] ?? null,
                'sold_median' => $median > 0 && (float)($comp['median_final_price'] ?? 0) > 0 ? round($median, 2) : null,
                'comp_count' => (int)($comp['samples'] ?? 0),
                'resale_basis' => $comp === [] ? null : $usedBasis,
                'comps_computed_at' => $comp['computed_at'] ?? null,
                'selling_fee_percent' => $this->pricing->sellingFee($row),
                'shipping' => $this->pricing->shippingFor($row),
            ];
        }
        return $result;
    }
}
