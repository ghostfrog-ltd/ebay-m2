<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\PriceRefresh;

use GhostFrog\Ebay\Model\Comps\Listings;
use GhostFrog\Ebay\Model\Comps\Valuation;
use GhostFrog\Ebay\Model\Opportunities\Rows;
use GhostFrog\Ebay\Model\ResourceModel\Listing;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;

final class Candidates
{
    public const CURSOR = 'ghostfrog_ebay_prequalification_cursor';
    public const URGENCY = 'CASE WHEN a.end_time <= UTC_TIMESTAMP() + INTERVAL 10 MINUTE THEN 0
        WHEN a.end_time <= UTC_TIMESTAMP() + INTERVAL 60 MINUTE THEN 1 ELSE 2 END';
    private const SCAN_LIMIT = 250;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Listings $listings,
        private readonly Valuation $valuation,
        private readonly Settings $settings,
        private readonly CacheInterface $cache
    ) {
    }

    public function scan(int $limit, bool $advance = false): array
    {
        $db = $this->resource->getConnection();
        $tracking = $advance || $db->tableColumnExists($this->resource->getTableName(Listing::TABLE), 'candidate_attempted_at');
        $select = $this->pool($tracking)->order(['a.end_time ASC', 'a.id ASC'])->limit(self::SCAN_LIMIT);
        $cursor = json_decode((string)$this->cache->load(self::CURSOR), true);
        $rows = is_array($cursor) ? $db->fetchAll((clone $select)->where(
            $db->quoteInto('a.end_time > ?', $cursor['end_time']) . ' OR ('
            . $db->quoteInto('a.end_time = ?', $cursor['end_time']) . ' AND '
            . $db->quoteInto('a.id > ?', $cursor['id']) . ')'
        )) : [];
        if ($rows === []) {
            $rows = $db->fetchAll($select);
        }

        $excluded = [];
        $bids = [];
        foreach ($rows as $listing) {
            $listing = $this->atBid($listing);
            $reason = $listing['queued'] !== null ? 'already_queued' : $this->exclusion($listing);
            if ($reason !== null) {
                $excluded[$reason] = ($excluded[$reason] ?? 0) + 1;
                continue;
            }
            $bids[] = $listing;
        }
        $values = $this->valuation->forLiveBatch($bids);
        $eligible = [];
        foreach ($bids as $listing) {
            $value = $values[$listing['id']] ?? null;
            $reason = $this->valuationExclusion($value);
            if ($reason !== null) {
                $excluded[$reason] = ($excluded[$reason] ?? 0) + 1;
                continue;
            }
            $eligible[] = [
                'listing_id' => (int)$listing['id'], 'end_time' => $listing['end_time'],
                'urgency' => (int)$listing['urgency'], 'stored_bid' => (float)$listing['price_current'],
                'model_key' => $value['model_key'], 'grade' => $value['condition_grade'],
                'comp_count' => $value['comp_count'], 'indicative_profit' => $value['expected_profit'],
                'max_safe_buy' => $value['max_safe_buy'],
            ];
        }
        usort($eligible, static fn (array $a, array $b): int =>
            [$a['urgency'], -$a['indicative_profit'], $a['end_time'], $a['listing_id']]
            <=> [$b['urgency'], -$b['indicative_profit'], $b['end_time'], $b['listing_id']]);
        if ($advance) {
            if (count($rows) === self::SCAN_LIMIT) {
                $this->cache->save(json_encode(array_intersect_key(end($rows), ['id' => true, 'end_time' => true])), self::CURSOR);
            } else {
                $this->cache->remove(self::CURSOR);
            }
        }
        return [
            'scanned' => count($rows), 'eligible' => count($eligible), 'excluded' => $excluded,
            'attempt_tracking_available' => $tracking,
            'selected' => array_slice($eligible, 0, $limit),
        ];
    }

    public function rejection(int $id): ?string
    {
        $listing = $this->resource->getConnection()->fetchRow($this->pool()->where('a.id = ?', $id));
        if (!$listing) {
            return 'outside_stale_auction_pool';
        }
        $listing = $this->atBid($listing);
        $reason = $this->exclusion($listing);
        if ($reason !== null) {
            return $reason;
        }
        $values = $this->valuation->forLiveBatch([$listing]);
        return $this->valuationExclusion($values[$id] ?? null);
    }

    private function pool(bool $tracking = true): Select
    {
        $hours = $this->settings->candidateHours();
        return $this->listings->liveSelect(requireFresh: false)
            ->columns([
                'candidate_attempted_at' => $tracking ? 'a.candidate_attempted_at' : new Expression('NULL'),
                'urgency' => new Expression(self::URGENCY),
            ])
            ->joinLeft(['o' => $this->resource->getTableName(Rows::TABLE)],
                'o.listing_id = a.id AND o.eligible_until > UTC_TIMESTAMP()', [])
            ->joinLeft(['q' => $this->resource->getTableName(Queue::TABLE)], 'q.listing_id = a.id', ['queued' => 'listing_id'])
            ->where('o.listing_id IS NULL')
            ->where("COALESCE(NULLIF(s.domain, ''), a.source) IN (?)", ['ebay-apple', 'ebay-ipod'])
            ->where("a.sale_type IN ('auction', 'both') AND a.end_time <= UTC_TIMESTAMP() + INTERVAL $hours HOUR")
            ->where('(' . Listings::FRESH_OBSERVATION . ') IS NOT TRUE');
    }

    private function exclusion(array $listing): ?string
    {
        return match (true) {
            $listing['candidate_attempted_at'] !== null => 'already_attempted',
            trim((string)$listing['model_key']) === '', str_starts_with(strtolower(trim($listing['model_key'])), 'unknown')
                => 'missing_model_key',
            !preg_match(Queue::ITEM_ID_PATTERN, $listing['external_id']) => 'unsupported_item_id',
            (float)$listing['price_current'] <= 0 => 'no_stored_bid',
            default => null,
        };
    }

    private function atBid(array $listing): array
    {
        $listing['price_current'] = $listing['price_bid_current'] > 0 ? $listing['price_bid_current']
            : ($listing['sale_type'] === 'auction' ? $listing['price_current'] : null);
        return $listing;
    }

    private function valuationExclusion(?array $value): ?string
    {
        return match (true) {
            $value === null => 'identity_condition_or_risk',
            !isset($value['meets_target']) => 'insufficient_comps',
            !$value['meets_target'] => 'below_profit_target',
            default => null,
        };
    }
}
