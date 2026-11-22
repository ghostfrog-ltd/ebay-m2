<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Comps;

use GhostFrog\Ebay\Model\Condition\Stage;
use GhostFrog\Ebay\Model\ResourceModel\Listing;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

final class Listings
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function sold(string $from, string $until, ?array $modelKeys = null): iterable
    {
        $db = $this->resource->getConnection();
        $sales = $this->select()
            ->where('a.status = ?', 'sold')
            ->where('a.finalized = 1')
            ->where('a.final_price > 0')
            ->where('a.end_time >= ?', $from)
            ->where('a.end_time <= ?', $until)
            ->where("a.model_key IS NOT NULL AND a.model_key NOT IN ('', 'unknown')");
        $select = (clone $sales)->where('a.id > :last_id')->order('a.id ASC')->limit(500);
        if ($modelKeys !== null) {
            $select->where('a.model_key IN (?)', $modelKeys);
        }
        $lastId = '0';
        do {
            $batch = $db->fetchAll($select, ['last_id' => $lastId]);
            foreach ($batch as $row) {
                $lastId = $row['id'];
                yield $row;
            }
            if ($modelKeys !== null && $batch !== []) {
                // An alias outside the selected models can win the deduplication.
                $aliases = $this->aliases($batch);
                if ($aliases !== []) {
                    yield from $db->fetchAll((clone $sales)->where('a.external_id IN (?)', $aliases));
                }
            }
        } while (count($batch) === 500);
    }

    public function related(array $ids): array
    {
        $db = $this->resource->getConnection();
        $rows = $db->fetchAll($this->select()->where('a.id IN (?)', $ids));
        $aliases = $this->aliases($rows);
        return $aliases === [] ? $rows : array_merge($rows,
            $db->fetchAll($this->select()->where('a.external_id IN (?)', $aliases)));
    }

    public static function itemId(string $externalId): string
    {
        return preg_replace('/^v[12]\|(\d+)\|0$/', '$1', $externalId);
    }

    private function aliases(array $listings): array
    {
        $aliases = [];
        foreach ($listings as $listing) {
            $id = self::itemId($listing['external_id']);
            if (ctype_digit($id)) {
                foreach ([$id, "v1|$id|0", "v2|$id|0"] as $alias) {
                    if ($alias !== $listing['external_id']) {
                        $aliases[$alias] = true;
                    }
                }
            }
        }
        return array_map('strval', array_keys($aliases));
    }

    public function live(int $id): ?array
    {
        $select = $this->select()
            ->where('a.id = ?', $id)
            ->where('a.status = ?', 'live')
            ->where('(a.end_time > UTC_TIMESTAMP() OR (a.end_time IS NULL AND a.sale_type = ?))', 'bin')
            ->where('a.price_current > 0')
            ->where('COALESCE(a.last_seen_at, a.first_seen) >= UTC_TIMESTAMP() - INTERVAL 24 HOUR');
        $row = $this->resource->getConnection()->fetchRow($select);
        return $row ?: null;
    }

    private function select(): Select
    {
        $db = $this->resource->getConnection();
        $select = $db->select()->from(['a' => $this->resource->getTableName(Listing::TABLE)], [
            'id', 'external_id', 'source', 'title', 'raw_attrs', 'model_key', 'condition_grade', 'final_price',
            'price_current', 'end_time', 'brand', 'model_name', 'storage_gb', 'colour', 'epid',
        ])
            ->joinLeft(['s' => $this->resource->getTableName('ghostfrog_ebay_sources')],
                's.id = a.source_id', ['source_domain' => 'domain']);
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
