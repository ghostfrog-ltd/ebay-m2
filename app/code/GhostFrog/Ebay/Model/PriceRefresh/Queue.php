<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\PriceRefresh;

use GhostFrog\Ebay\Model\Indexing\Transaction;
use GhostFrog\Ebay\Model\Notification\Queue as Notifications;
use GhostFrog\Ebay\Model\Opportunities\Rows;
use GhostFrog\Ebay\Model\ResourceModel\Listing;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;

final class Queue
{
    public const TABLE = 'ghostfrog_ebay_price_refresh_queue';
    private const IMMINENT = "a.sale_type IN ('auction', 'both') AND a.end_time > UTC_TIMESTAMP()
        AND a.end_time <= UTC_TIMESTAMP() + INTERVAL 10 MINUTE";

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Settings $settings,
        private readonly Transaction $transaction,
        private readonly Rows $opportunities,
        private readonly Notifications $notifications
    ) {
    }

    public function enqueueDue(int $limit, int $minute): int
    {
        $db = $this->resource->getConnection();
        $due = $this->dueExpression();
        $select = $this->eligible()->reset(Select::COLUMNS)->columns([
            'listing_id' => 'a.id',
            'due_at' => new Expression($due),
            'available_at' => new Expression('UTC_TIMESTAMP()'),
        ])->joinLeft(['q' => $this->table()], 'q.listing_id = a.id', [])
            ->where('q.listing_id IS NULL')->where("($due) <= UTC_TIMESTAMP()");
        $rows = $this->prioritized($select, 'due_at', $limit, $minute);
        return $rows === [] ? 0 : $db->insertOnDuplicate($this->table(), $rows, ['listing_id']);
    }

    public function request(int $id): void
    {
        $db = $this->resource->getConnection();
        $db->insertOnDuplicate($this->table(), [
            'listing_id' => $id,
            'due_at' => new Expression('UTC_TIMESTAMP()'),
            'available_at' => new Expression('UTC_TIMESTAMP()'),
        ], ['listing_id']);
    }

    public function due(int $limit, int $minute): array
    {
        $db = $this->resource->getConnection();
        $select = $db->select()->from(['q' => $this->table()])
            ->join(['a' => $this->listingTable()], 'a.id = q.listing_id', [])
            ->where('q.available_at <= UTC_TIMESTAMP()');
        return $this->prioritized($select, 'q.due_at', $limit, $minute);
    }

    public function listing(int $id): ?array
    {
        $db = $this->resource->getConnection();
        return $db->fetchRow($this->eligible()->where('a.id = ?', $id)) ?: null;
    }

    public function discardObsolete(int $limit): int
    {
        $db = $this->resource->getConnection();
        $ids = $db->fetchCol($db->select()->from(['q' => $this->table()], ['listing_id'])
            ->join(['a' => $this->listingTable()], 'a.id = q.listing_id', [])
            ->joinLeft(['o' => $this->resource->getTableName(Rows::TABLE)], 'o.listing_id = a.id', [])
            ->where("o.listing_id IS NULL OR o.eligible_until <= UTC_TIMESTAMP()
                OR a.status <> 'live' OR COALESCE(a.finalized, 0) = 1 OR a.end_time <= UTC_TIMESTAMP()")
            ->order('q.due_at ASC')->limit($limit));
        return $ids === [] ? 0 : $db->delete($this->table(), ['listing_id IN (?)' => $ids]);
    }

    public function record(int $id, array $snapshot, array $fields, string $startedAt): bool
    {
        $db = $this->resource->getConnection();
        $recorded = false;
        $this->transaction->run(function () use ($db, $id, $snapshot, $fields, $startedAt, &$recorded): void {
            $current = $db->fetchRow($db->select()->from($this->listingTable(), array_keys($snapshot))
                ->where('id = ?', $id)->forUpdate());
            if ($current !== $snapshot || $current['last_seen_at'] > $startedAt) {
                $this->finish($id);
                return;
            }

            $fields['last_seen_at'] = $startedAt;
            if ($fields['status'] === 'live') {
                $fields['last_price_checked_at'] = $startedAt;
            } else {
                $db->insertOnDuplicate($this->resource->getTableName('ghostfrog_ebay_listing_statuses'), [
                    'status' => 'unknown', 'lifecycle_stage' => 'pending_outcome', 'is_live_like' => 0,
                    'is_terminal' => 0, 'description' => 'The current listing outcome is unconfirmed.',
                ], ['status']);
            }
            $db->update($this->listingTable(), $fields, ['id = ?' => $id]);
            if (isset($fields['price_current'])) {
                $db->insert($this->resource->getTableName('ghostfrog_ebay_auction_price_history'), [
                    'external_id' => $current['external_id'],
                    'price' => $fields['price_current'],
                    'bin_price' => $fields['price_bin_current'],
                    'bids_count' => $fields['bids_count'],
                    'timestamp' => $startedAt,
                    'recorded_at' => $startedAt,
                ]);
            }
            // Reuse the individual index write under its lock. The listing change also reaches the scheduled view.
            $this->opportunities->refreshListings([$id]);
            $this->notifications->observe($id);
            $this->finish($id);
            $recorded = true;
        });
        return $recorded;
    }

    public function finish(int $id): void
    {
        $this->resource->getConnection()->delete($this->table(), ['listing_id = ?' => $id]);
    }

    public function retry(array $job): void
    {
        $attempts = (int)$job['attempts'];
        $this->resource->getConnection()->update($this->table(), [
            'available_at' => gmdate('Y-m-d H:i:s', time() + min(900, 60 * 2 ** min($attempts, 4))),
            'attempts' => min(255, $attempts + 1),
        ], ['listing_id = ?' => $job['listing_id']]);
    }

    public function metrics(): array
    {
        $db = $this->resource->getConnection();
        $queue = $db->fetchRow($db->select()->from($this->table(), [
            'depth' => new Expression('COUNT(*)'),
            'oldest_due_at' => new Expression('MIN(due_at)'),
        ]));
        $due = $this->dueExpression();
        $queue['stale'] = $db->fetchOne($this->eligible()
            ->reset(Select::COLUMNS)->columns(new Expression('COUNT(*)'))
            ->where("($due) <= UTC_TIMESTAMP() OR a.last_price_checked_at IS NULL
                OR NOT (a.last_seen_at <=> a.last_price_checked_at)"));
        return $queue;
    }

    private function eligible(): Select
    {
        return $this->resource->getConnection()->select()->from(['a' => $this->listingTable()], [
            'id', 'external_id', 'status', 'finalized', 'final_price', 'end_time', 'sale_type',
            'last_seen_at', 'last_price_checked_at', 'price_current', 'price_bid_current', 'price_bin_current', 'bids_count',
        ])->join(['o' => $this->resource->getTableName(Rows::TABLE)], 'o.listing_id = a.id', [])
            ->where("a.status = 'live' AND COALESCE(a.finalized, 0) = 0 AND COALESCE(a.final_price, 0) <= 0")
            ->where("a.sale_type IN ('auction', 'both', 'bin')")
            ->where("a.end_time > UTC_TIMESTAMP() OR (a.end_time IS NULL AND a.sale_type = 'bin')")
            ->where('o.eligible_until > UTC_TIMESTAMP()')
            ->where("a.external_id REGEXP '^([0-9]{1,20}|v[12][|][0-9]{1,20}[|][0-9]{1,20})$'");
    }

    private function dueExpression(): string
    {
        $bin = $this->settings->binInterval();
        $auction = 'CASE';
        foreach ($this->settings->auctionIntervals() as $window => $interval) {
            $auction .= " WHEN a.end_time <= UTC_TIMESTAMP() + INTERVAL $window SECOND THEN $interval";
        }
        $auction .= ' ELSE NULL END';
        $interval = "CASE a.sale_type WHEN 'bin' THEN $bin
            WHEN 'both' THEN LEAST($bin, COALESCE(($auction), $bin)) ELSE ($auction) END";
        $due = "IF(($interval) IS NULL, NULL, IF(a.last_price_checked_at IS NULL,
            COALESCE(a.last_seen_at, UTC_TIMESTAMP()),
            DATE_ADD(a.last_price_checked_at, INTERVAL ($interval) SECOND)))";
        if ($this->settings->slackDestination() !== null) {
            $window = Settings::AUCTION_ALERT_WINDOW;
            $opens = "a.end_time - INTERVAL $window SECOND";
            $due = "CASE WHEN a.sale_type IN ('auction', 'both') AND $opens <= UTC_TIMESTAMP()
                AND (a.last_price_checked_at IS NULL OR a.last_price_checked_at < $opens)
                THEN LEAST(($due), $opens) ELSE ($due) END";
        }
        return $due;
    }

    private function prioritized(Select $select, string $due, int $limit, int $minute): array
    {
        $db = $this->resource->getConnection();
        // Reserve an early worker slot for other due work. A one-item batch rotates the slot.
        $reserve = $limit > 1 || $minute % 4 === 0;
        $other = $reserve ? $db->fetchRow((clone $select)->where('NOT (' . self::IMMINENT . ')')
            ->order([$due . ' ASC', 'a.id ASC'])->limit(1)) : false;
        if ($other) {
            $select->where('a.id <> ?', $other['listing_id']);
        }
        $remaining = $limit - (int)(bool)$other;
        $rows = [];
        if ($remaining > 0) {
            $rows = $db->fetchAll($select
                ->order(new Expression('CASE WHEN ' . self::IMMINENT . ' THEN 0 ELSE 1 END ASC'))
                ->order(new Expression('CASE WHEN ' . self::IMMINENT . ' THEN a.end_time END ASC'))
                ->order([$due . ' ASC', 'a.id ASC'])->limit($remaining));
        }
        if ($other) {
            array_splice($rows, 1, 0, [$other]);
        }
        return $rows;
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }

    private function listingTable(): string
    {
        return $this->resource->getTableName(Listing::TABLE);
    }
}
