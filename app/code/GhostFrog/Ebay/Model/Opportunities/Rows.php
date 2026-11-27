<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Opportunities;

use GhostFrog\Ebay\Model\Comps\Listings;
use GhostFrog\Ebay\Model\Comps\Valuation;
use Magento\Framework\App\ResourceConnection;

/** Writes opportunity rows inside the caller's index transaction. */
final class Rows
{
    public const TABLE = 'ghostfrog_ebay_opportunities';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Listings $listings,
        private readonly Valuation $valuation
    ) {
    }

    public function rebuild(): void
    {
        $this->resource->getConnection()->delete($this->table());
        $this->write($this->listings->liveBatch());
    }

    public function refreshListings(array $ids): void
    {
        $this->resource->getConnection()->delete($this->table(), ['listing_id IN (?)' => $ids]);
        $this->write($this->listings->liveBatch($ids));
    }

    public function refreshCohorts(array $cohorts): void
    {
        foreach ($cohorts as $key => $grades) {
            $this->resource->getConnection()->delete(
                $this->table(),
                ['model_key = ?' => $key, 'condition_grade IN (?)' => array_keys($grades)]
            );
        }
        if ($cohorts !== []) {
            $this->write($this->listings->liveBatch(null, array_keys($cohorts)), $cohorts);
        }
    }

    public function expire(): void
    {
        $db = $this->resource->getConnection();
        $select = $db->select()->from($this->table(), ['listing_id'])
            ->where('eligible_until <= UTC_TIMESTAMP()')
            ->where('listing_id > :last_id')->order('listing_id ASC')->limit(500);

        $lastId = 0;
        while ($ids = $db->fetchCol($select, ['last_id' => $lastId])) {
            $lastId = end($ids);
            // Re-read the listing in case its deadline was extended since the last update.
            $this->refreshListings($ids);
        }
    }

    private function write(iterable $batches, ?array $cohorts = null): void
    {
        $db = $this->resource->getConnection();
        foreach ($batches as $batch) {
            foreach ($batch as $offset => $listing) {
                if ($listing['sale_type'] === 'both' && $listing['price_bin_current'] > 0
                    && $listing['price_bin_current'] < $listing['price_current']) {
                    $batch[$offset]['price_current'] = $listing['price_bin_current'];
                }
            }
            $valuations = $this->valuation->forLiveBatch($batch, $cohorts);
            $rows = [];
            foreach ($batch as $listing) {
                $value = $valuations[$listing['id']] ?? [];
                if (empty($value['meets_target'])) {
                    continue;
                }
                $rows[] = [
                    'listing_id' => $listing['id'],
                    'title' => $listing['title'],
                    'source' => $listing['source_domain'] ?: $listing['source'],
                    'sale_type' => $listing['sale_type'],
                    'model_key' => $value['model_key'],
                    'condition_grade' => $value['condition_grade'],
                    'current_price' => $listing['price_current'],
                    'bid_price' => $listing['price_bid_current'],
                    'bin_price' => $listing['price_bin_current'],
                    'best_offer' => (int)$listing['best_offer'],
                    'last_seen_at' => $listing['last_seen_at'],
                    'last_price_checked_at' => $listing['last_price_checked_at'],
                    'sold_median' => $value['sold_median'],
                    'working_median' => $value['working_median'] ?? null,
                    'comp_count' => $value['comp_count'],
                    'max_safe_buy' => $value['max_safe_buy'],
                    'shipping' => $value['shipping'],
                    'expected_profit' => $value['expected_profit'],
                    'expected_roi_percent' => $value['expected_roi_percent'],
                    'end_time' => $listing['end_time'],
                    'eligible_until' => $listing['eligible_until'],
                    'external_id' => $listing['external_id'],
                    'url' => $listing['url'],
                ];
            }
            if ($rows !== []) {
                $db->insertOnDuplicate($this->table(), $rows);
            }
        }
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
