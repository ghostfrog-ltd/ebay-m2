<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Service;

use GhostFrog\Ebay\Api\ListingOutcomesInterface;
use GhostFrog\Ebay\Model\ResourceModel\Listing;
use Magento\Framework\App\ResourceConnection;

/** Listing status and final price as core's final-price worker recorded them. Read-only. */
final class ListingOutcomes implements ListingOutcomesInterface
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function forListings(array $listingIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $listingIds))));
        if ($ids === []) {
            return [];
        }
        $db = $this->resource->getConnection();
        $rows = [];
        foreach (array_chunk($ids, 500) as $batch) {
            $select = $db->select()->from($this->resource->getTableName(Listing::TABLE), [
                'listing_id' => 'id', 'status', 'finalized', 'final_price', 'bids_count', 'end_time',
                'price_current', 'outcome_reason' => 'backfill_last_reason', 'last_seen_at',
            ])->where('id IN (?)', $batch);
            foreach ($db->fetchAll($select) as $row) {
                $rows[(int)$row['listing_id']] = [
                    'listing_id' => (int)$row['listing_id'],
                    'status' => (string)$row['status'],
                    'finalized' => (bool)$row['finalized'],
                    'final_price' => $row['final_price'] === null ? null : (float)$row['final_price'],
                    'bids_count' => $row['bids_count'] === null ? null : (int)$row['bids_count'],
                    'end_time' => $row['end_time'],
                    'price_current' => $row['price_current'] === null ? null : (float)$row['price_current'],
                    'outcome_reason' => $row['outcome_reason'],
                    'last_seen_at' => $row['last_seen_at'],
                ];
            }
        }
        return $rows;
    }
}
