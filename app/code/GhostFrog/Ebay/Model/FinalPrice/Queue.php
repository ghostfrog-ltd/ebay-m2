<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\FinalPrice;

use GhostFrog\Ebay\Model\ResourceModel\Listing;
use GhostFrog\Ebay\Model\ResourceModel\Source;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Throwable;

final class Queue
{
    private const TABLE = 'ghostfrog_ebay_final_price_queue';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function fill(int $buffer): void
    {
        $db = $this->resource->getConnection();
        $table = $this->table();
        $ready = (int)$db->fetchOne($db->select()->from($table, new Expression('COUNT(*)'))
            ->where('available_at <= UTC_TIMESTAMP()'));
        if ($ready >= $buffer) {
            return;
        }

        $select = $this->eligible()->reset(Select::COLUMNS)->columns(['listing_id' => 'id'], 'a')
            ->columns(['available_at' => new Expression('UTC_TIMESTAMP()')])
            ->joinLeft(['q' => $table], 'q.listing_id = a.id', [])
            // Listings with a known end time come first so fresh sales reach comps quickly. The
            // no-end-time backlog follows, oldest first, so items nearest eBay's cutoff are not lost.
            ->where('q.listing_id IS NULL')
            ->order([
                new Expression('a.end_time IS NULL'),
                new Expression('COALESCE(a.end_time, a.last_seen_at) ASC'),
                'a.id ASC',
            ])
            ->limit($buffer - $ready);
        $rows = $db->fetchAll($select);
        if ($rows !== []) {
            $db->insertOnDuplicate($table, $rows, ['listing_id']);
        }
    }

    /**
     * Queues eligible listings that are not already queued, ahead of the routine backlog. A listing already in
     * the queue keeps its place and retry delay, so repeated requests never bypass the backoff.
     *
     * @param int[] $ids
     * @return int Listings newly queued.
     */
    public function prioritise(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return 0;
        }
        $db = $this->resource->getConnection();
        $table = $this->table();
        // Due from the listing's end time, so it sorts ahead of listings queued later for routine checks.
        $select = $this->eligible()->reset(Select::COLUMNS)->columns(['listing_id' => 'id'], 'a')
            ->columns(['available_at' => new Expression('LEAST(COALESCE(a.end_time, UTC_TIMESTAMP()), UTC_TIMESTAMP())')])
            ->joinLeft(['q' => $table], 'q.listing_id = a.id', [])
            ->where('q.listing_id IS NULL')
            ->where('a.id IN (?)', $ids);
        $rows = $db->fetchAll($select);
        return $rows === [] ? 0 : $db->insertOnDuplicate($table, $rows, ['listing_id']);
    }

    public function due(int $limit): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchAll($db->select()->from($this->table(), ['listing_id', 'needs_comp'])
            ->where('available_at <= UTC_TIMESTAMP()')
            ->order(['needs_comp DESC', 'available_at ASC', 'listing_id ASC'])->limit($limit));
    }

    public function listing(string $id): ?array
    {
        $row = $this->resource->getConnection()->fetchRow($this->eligible()->where('a.id = ?', $id));
        return $row ?: null;
    }

    public function finish(array $ids): void
    {
        $this->resource->getConnection()->delete($this->table(), ['listing_id IN (?)' => $ids]);
    }

    public function record(string $id, array $checked, array $result): bool
    {
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $current = $db->fetchRow($this->eligible()->where('a.id = ?', $id)->forUpdate());
            if (!$current || $current['external_id'] !== $checked['external_id']
                || $current['end_time'] !== $checked['end_time'] || $current['last_seen_at'] !== $checked['last_seen_at']
            ) {
                $this->finish([$id]);
                $db->commit();
                return false;
            }

            $status = $result['status'];
            $attempts = (int)$current['backfill_check_count'];
            $fields = [
                'backfill_checked_at' => new Expression('UTC_TIMESTAMP()'),
                'backfill_check_count' => $attempts + 1,
                'backfill_last_reason' => $result['reason'],
            ];
            if ($status === 'unavailable') {
                $status = $current['status'] === 'ended' ? 'ended' : 'unknown';
            }
            if ($status !== 'retry') {
                $terminal = in_array($status, ['sold', 'ended'], true);
                $this->ensureStatus($status, $terminal);
                $fields += ['status' => $status, 'finalized' => (int)$terminal, 'final_price' => $result['final_price'] ?? null];
                if (isset($result['end_time'])) {
                    $fields += ['end_time' => $result['end_time'], 'time_left_s' => $terminal ? 0 : null];
                }
                if (isset($result['bids_count'])) {
                    $fields['bids_count'] = $result['bids_count'];
                }
            }
            $db->update($this->resource->getTableName(Listing::TABLE), $fields, ['id = ?' => $id]);

            if ($status === 'retry') {
                $db->update($this->table(), [
                    'available_at' => gmdate('Y-m-d H:i:s', time() + 300 * 2 ** min($attempts, 8)),
                ], ['listing_id = ?' => $id]);
            } elseif ($status === 'sold') {
                // Keep the job until the comp write succeeds, even across a worker restart.
                $db->update($this->table(), ['needs_comp' => 1], ['listing_id = ?' => $id]);
            } else {
                $this->finish([$id]);
            }
            $db->commit();
            return $status === 'sold';
        } catch (Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    private function eligible(): Select
    {
        $db = $this->resource->getConnection();
        return $db->select()
            ->from(['a' => $this->resource->getTableName(Listing::TABLE)], [
                'id', 'external_id', 'status', 'end_time', 'last_seen_at', 'backfill_check_count',
            ])
            // Search results carry no end time for Good 'Til Cancelled listings, so one that has dropped
            // out of search for two days is checked as if it ended when it was last seen. eBay keeps
            // ended items for about 90 days, which bounds both cases.
            ->where('(a.end_time BETWEEN UTC_TIMESTAMP() - INTERVAL 90 DAY AND UTC_TIMESTAMP() - INTERVAL 30 MINUTE
                OR a.end_time IS NULL
                    AND a.last_seen_at BETWEEN UTC_TIMESTAMP() - INTERVAL 90 DAY AND UTC_TIMESTAMP() - INTERVAL 2 DAY)')
            ->where('(a.final_price IS NULL OR a.final_price <= 0)')
            ->where("(a.status IN ('live', 'active', 'open', 'ending', 'ending_soon', 'unknown') AND COALESCE(a.finalized, 0) = 0
                OR a.status = 'parse_failed' OR (a.status = 'ended' AND a.backfill_checked_at IS NULL))")
            ->where("a.external_id REGEXP '^([0-9]{1,20}|v[12][|][0-9]{1,20}[|]0)$'")
            ->where("(COALESCE(a.backfill_last_reason, '') <> 'trading_item_unavailable'
                OR a.last_seen_at > a.backfill_checked_at)")
            // Queued jobs for a paused source finish without an eBay call and are refilled once re-enabled.
            ->where(Source::activeListing($this->resource));
    }

    private function ensureStatus(string $status, bool $terminal): void
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('ghostfrog_ebay_listing_statuses');
        $db->insertOnDuplicate($table, [
            'status' => $status,
            'lifecycle_stage' => $status === 'unknown' ? 'pending_outcome' : ($terminal ? 'terminal' : 'live'),
            'is_live_like' => (int)($status === 'live'),
            'is_terminal' => (int)$terminal,
            'description' => match ($status) {
                'sold' => 'eBay supplied evidence of a sale and a usable final price.',
                'ended' => 'eBay reports the listing ended without a verified final price.',
                'live' => 'Listing was last seen active on eBay.',
                'unknown' => 'The current listing outcome is unconfirmed.',
            },
            'created_at' => new Expression('UTC_TIMESTAMP()'),
        ], ['status']);
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
