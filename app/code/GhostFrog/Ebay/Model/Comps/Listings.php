<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Comps;

use GhostFrog\Ebay\Model\Collection\SourceSearches;
use GhostFrog\Ebay\Model\Condition\Stage;
use GhostFrog\Ebay\Model\ModelKey\Pending;
use GhostFrog\Ebay\Model\ResourceModel\Listing;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;

final class Listings
{
    public const FRESH_OBSERVATION = 'COALESCE(a.last_seen_at, a.first_seen) >= UTC_TIMESTAMP() - INTERVAL 24 HOUR';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function sold(string $from, string $until): iterable
    {
        foreach ($this->batches($this->soldSelect($from, $until)) as $batch) {
            yield from $batch;
        }
    }

    public function soldItemIds(string $from, string $until, array $ids): array
    {
        $db = $this->resource->getConnection();
        $select = $this->soldSelect($from, $until, false)->where('a.id IN (?)', $ids);
        return array_map(self::itemId(...), $db->fetchCol($select));
    }

    public function soldItems(string $from, string $until, array $itemIds): array
    {
        $select = $this->soldSelect($from, $until)->where('a.external_id IN (?)', $this->externalIds($itemIds));
        return $this->resource->getConnection()->fetchAll($select);
    }

    private function soldSelect(string $from, string $until, bool $includeEvidence = true): Select
    {
        $select = $includeEvidence
            ? $this->select()->columns(['sale_type' => 'a.sale_type'])
            : $this->resource->getConnection()->select()
                ->from(['a' => $this->resource->getTableName(Listing::TABLE)], ['external_id']);
        return $select
            ->where('a.status = ?', 'sold')
            ->where('a.finalized = 1')
            ->where('a.final_price > 0')
            ->where('a.end_time >= ?', $from)
            ->where('a.end_time <= ?', $until)
            ->where("a.model_key IS NOT NULL AND a.model_key NOT IN ('', 'unknown')")
            // A sale keyed at collection joins its cohort once the checks have confirmed the key.
            ->where('NOT (' . Pending::sql('a') . ')');
    }

    public static function itemId(string $externalId): string
    {
        return preg_replace('/^v[12]\|(\d+)\|0$/', '$1', $externalId);
    }

    private function externalIds(array $items): array
    {
        $ids = [];
        foreach ($items as $id) {
            $id = (string)$id;
            $ids[] = $id;
            if (ctype_digit($id)) {
                $ids[] = "v1|$id|0";
                $ids[] = "v2|$id|0";
            }
        }
        return array_unique($ids);
    }

    public function live(int $id): ?array
    {
        $row = $this->resource->getConnection()->fetchRow($this->liveSelect()->where('a.id = ?', $id));
        return $row ?: null;
    }

    public function liveBatch(?array $ids = null, ?array $modelKeys = null): iterable
    {
        $select = $this->liveSelect()->where("a.model_key IS NOT NULL AND a.model_key NOT IN ('', 'unknown')");
        if ($ids !== null) {
            $select->where('a.id IN (?)', $ids);
        }
        if ($modelKeys !== null) {
            $select->where('a.model_key IN (?)', $modelKeys);
        }
        yield from $this->batches($select);
    }

    public function liveSelect(bool $requireFresh = true): Select
    {
        $freshUntil = 'DATE_ADD(COALESCE(a.last_seen_at, a.first_seen), INTERVAL 24 HOUR)';
        $select = $this->select()
            ->columns([
                'sale_type' => 'a.sale_type',
                'url' => 'a.url',
                'price_bid_current' => 'a.price_bid_current',
                'price_bin_current' => 'a.price_bin_current',
                'last_seen_at' => 'a.last_seen_at',
                'last_price_checked_at' => 'a.last_price_checked_at',
                'eligible_until' => new Expression("LEAST(COALESCE(a.end_time, $freshUntil), $freshUntil)"),
            ])
            ->where('a.status = ?', 'live')
            ->where('COALESCE(a.finalized, 0) = 0 AND COALESCE(a.final_price, 0) <= 0')
            ->where('(a.end_time > UTC_TIMESTAMP() OR (a.end_time IS NULL AND a.sale_type = ?))', 'bin')
            ->where('a.price_current > 0')
            // Paused sources drop out of opportunities, price checks and alerts.
            ->where('s.id IS NULL OR COALESCE(s.enabled, 0) = 1')
            // So do collect-only sources, which are kept for their price history.
            ->where('s.domain IS NULL OR s.domain NOT IN (?)', SourceSearches::collectOnly());
        return $requireFresh ? $select->where(self::FRESH_OBSERVATION) : $select;
    }

    private function batches(Select $select): iterable
    {
        $db = $this->resource->getConnection();
        $select->where('a.id > :last_id')->order('a.id ASC')->limit(500);
        $lastId = 0;
        while ($batch = $db->fetchAll($select, ['last_id' => $lastId])) {
            yield $batch;
            $lastId = end($batch)['id'];
        }
    }

    private function select(): Select
    {
        $db = $this->resource->getConnection();
        $select = $db->select()->from(['a' => $this->resource->getTableName(Listing::TABLE)], [
            'id', 'external_id', 'source', 'title', 'raw_attrs', 'model_key', 'condition_grade', 'final_price',
            'price_current', 'end_time', 'brand', 'model_name', 'storage_gb', 'colour', 'epid',
        ])
            ->joinLeft(['s' => $this->resource->getTableName('ghostfrog_ebay_sources')],
                's.id = a.source_id', [
                    'source_domain' => 'domain',
                    'source_min_profit' => 'min_profit',
                    'source_min_roi_percent' => 'min_roi_percent',
                    'source_shipping' => 'shipping',
                    'source_min_comps' => 'min_comps',
                    'source_selling_fee_percent' => 'selling_fee_percent',
                ]);
        foreach (Stage::cases() as $stage) {
            $alias = $stage->value . '_condition';
            $table = $this->resource->getTableName('ghostfrog_ebay_listing_' . $stage->value . '_conditions');
            $latest = $db->select()->from(['c' => $table], ['id'])->where('c.listing_id = a.id')
                ->order(['c.checked_at DESC', 'c.id DESC'])->limit(1);
            $select->joinLeft([$alias => $table], "$alias.id = ($latest)", [
                $stage->gradeField(), $stage->value . '_review' => 'review_required',
            ]);
        }
        $review = $db->select()->from(['r' => $this->resource->getTableName('ghostfrog_ebay_listing_review_actions')], ['id'])
            ->where('r.external_id = a.external_id')->order(['r.created_at DESC', 'r.id DESC'])->limit(1);

        return $select->joinLeft(
            ['review' => $this->resource->getTableName('ghostfrog_ebay_listing_review_actions')],
            "review.id = ($review)",
            ['review_action' => 'action']
        );
    }
}
