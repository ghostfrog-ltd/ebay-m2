<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Service;

use GhostFrog\Ebay\Api\AuctionCandidatesInterface;
use GhostFrog\Ebay\Model\Comps\Listings;
use GhostFrog\Ebay\Model\Comps\Pricing;
use GhostFrog\Ebay\Model\Comps\Valuation;
use GhostFrog\Ebay\Model\Condition\Stage;
use GhostFrog\Ebay\Model\Opportunities\Rows;
use GhostFrog\Ebay\Model\ResourceModel\Listing;
use Magento\Framework\App\ResourceConnection;

/**
 * Live auctions from the Opportunities index, revalued with core's own Valuation and Pricing so the numbers
 * match the ranking. Read-only.
 */
final class AuctionCandidates implements AuctionCandidatesInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Listings $listings,
        private readonly Valuation $valuation,
        private readonly Pricing $pricing
    ) {
    }

    public function endingBetween(string $fromUtc, string $untilUtc): array
    {
        $db = $this->resource->getConnection();
        $ids = $db->fetchCol($db->select()
            ->from($this->resource->getTableName(Rows::TABLE), ['listing_id'])
            ->where('sale_type = ?', 'auction')
            ->where('eligible_until > UTC_TIMESTAMP()')
            ->where('end_time >= ?', $fromUtc)
            ->where('end_time <= ?', $untilUtc)
            ->order('end_time ASC'));
        if ($ids === []) {
            return [];
        }

        $extra = [];
        $select = $db->select()->from(['a' => $this->resource->getTableName(Listing::TABLE)], ['id', 'bids_count'])
            ->where('a.id IN (?)', $ids);
        foreach (Stage::cases() as $stage) {
            $table = $this->resource->getTableName($stage->assessmentTable());
            $latest = $db->select()->from(['c' => $table], ['id'])->where('c.listing_id = a.id')
                ->order(['c.checked_at DESC', 'c.id DESC'])->limit(1);
            $alias = $stage->value . '_c';
            $select->joinLeft([$alias => $table], "$alias.id = ($latest)", [
                $stage->value . '_confidence' => 'confidence',
            ]);
        }
        foreach ($db->fetchAll($select) as $row) {
            $extra[(int)$row['id']] = $row;
        }

        $rows = [];
        foreach ($this->listings->liveBatch($ids) as $batch) {
            $values = $this->valuation->forLiveBatch($batch);
            foreach ($batch as $listing) {
                $id = (int)$listing['id'];
                $value = $values[$id] ?? null;
                if ($value === null || ($listing['sale_type'] ?? null) !== 'auction' || !isset($value['expected_profit'])) {
                    continue;
                }
                $rows[$id] = [
                    'listing_id' => $id,
                    'external_id' => (string)$listing['external_id'],
                    'title' => (string)$listing['title'],
                    'url' => $listing['url'],
                    'source' => (string)(($listing['source_domain'] ?? null) ?: $listing['source']),
                    'item_type' => $value['item_type'],
                    'model_key' => $value['model_key'],
                    'condition_grade' => $value['condition_grade'],
                    'price_current' => (float)$listing['price_current'],
                    'bids_count' => isset($extra[$id]['bids_count']) ? (int)$extra[$id]['bids_count'] : null,
                    'end_time' => $listing['end_time'],
                    'last_seen_at' => $listing['last_seen_at'],
                    'last_price_checked_at' => $listing['last_price_checked_at'],
                    'sold_median' => (float)$value['sold_median'],
                    'resale_basis' => $value['resale_basis'],
                    'comp_count' => (int)$value['comp_count'],
                    'comp_minimum' => (int)$value['minimum'],
                    'comps_computed_at' => $value['computed_at'],
                    'expected_profit' => (float)$value['expected_profit'],
                    'max_safe_buy' => (float)$value['max_safe_buy'],
                    'shipping' => (float)$value['shipping'],
                    'inbound_postage' => Pricing::listingPostage($listing),
                    'selling_fee_percent' => $this->pricing->sellingFee($listing),
                    'text_grade' => $listing['text_grade'] ?? null,
                    'text_confidence' => self::number($extra[$id]['text_confidence'] ?? null),
                    'text_review' => isset($listing['text_review']) ? (bool)$listing['text_review'] : null,
                    'visual_grade' => $listing['visual_grade'] ?? null,
                    'visual_confidence' => self::number($extra[$id]['visual_confidence'] ?? null),
                    'visual_review' => isset($listing['visual_review']) ? (bool)$listing['visual_review'] : null,
                ];
            }
        }
        return $rows;
    }

    private static function number(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float)$value;
    }
}
