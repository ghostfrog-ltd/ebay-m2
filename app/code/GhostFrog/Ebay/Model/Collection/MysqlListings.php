<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Collection;

use DateTimeImmutable;
use DateTimeZone;
use GhostFrog\Ebay\Model\Enrichment\Queue;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Lock\LockManagerInterface;
use RuntimeException;
use Throwable;

final class MysqlListings
{
    private const LOCK_NAME = 'ghostfrog_ebay_collection';
    private const REFRESHABLE_STATUSES = ['live', 'active', 'open', 'ending', 'ending_soon', 'unknown'];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LockManagerInterface $lock,
        private readonly Queue $enrichmentQueue
    ) {
    }

    public function enabledSources(): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchAll($db->select()
            ->from($this->resource->getTableName('ghostfrog_ebay_sources'), [
                'id', 'name', 'domain', 'scrape_interval_seconds', 'last_scraped_at',
            ])
            ->where('enabled = ?', 1)
            ->where('domain IS NOT NULL')
            ->order(['last_scraped_at ASC', 'id ASC']));
    }

    public function tryLock(): bool
    {
        return $this->lock->lock(self::LOCK_NAME, 0);
    }

    public function unlock(): void
    {
        $this->lock->unlock(self::LOCK_NAME);
    }

    public function save(
        array $source,
        array $rows,
        array $unavailable,
        DateTimeImmutable $observedAt,
        DateTimeImmutable $staleBefore
    ): int {
        $db = $this->resource->getConnection();
        $sources = $this->resource->getTableName('ghostfrog_ebay_sources');
        $listings = $this->resource->getTableName('ghostfrog_ebay_auction_listings');
        $history = $this->resource->getTableName('ghostfrog_ebay_auction_price_history');
        $seen = $this->utcDateTime($observedAt);
        if ($rows !== []) {
            $this->assertDecimalPrices($db, $listings, $history);
        }

        $db->beginTransaction();
        try {
            $enabled = $db->fetchOne($db->select()
                ->from($sources, 'enabled')->where('id = ?', $source['id'])->forUpdate());
            if ((int)$enabled !== 1) {
                $db->commit();
                return 0;
            }

            if ($rows !== [] || $unavailable !== []) {
                $this->ensureStatuses($db);
            }
            $written = 0;
            ksort($rows, SORT_STRING);
            foreach ($rows as $externalId => $row) {
                $externalId = (string)$externalId;
                if (!$this->saveRow($db, $listings, $source, $externalId, $row, $seen)) {
                    continue;
                }
                $written++;
                if (($row['price_current'] ?? 0) > 0) {
                    $db->insert($history, [
                        'external_id' => $externalId,
                        'price' => $row['price_current'],
                        'bids_count' => $row['bids_count'],
                        'timestamp' => $seen,
                        'recorded_at' => $seen,
                    ]);
                }
            }

            $live = ['status = ?' => 'live', 'COALESCE(finalized, 0) = 0'];
            if ($unavailable !== []) {
                $db->update($listings, ['status' => 'unknown'], $live + [
                    'external_id IN (?)' => array_map('strval', $unavailable),
                    '(last_seen_at IS NULL OR last_seen_at < ?)' => $seen,
                ]);
            }
            if ($rows !== []) {
                $db->update($listings, ['status' => 'unknown'], $live + [
                    'source_id = ?' => $source['id'],
                    '(end_time IS NULL OR end_time <= ?)' => $seen,
                    '(last_seen_at IS NULL OR last_seen_at < ?)' => $this->utcDateTime($staleBefore),
                ]);
            }

            $db->update($sources, ['last_scraped_at' => new Expression('UTC_TIMESTAMP()')], [
                'id = ?' => $source['id'],
            ]);
            $db->commit();
            return $written;
        } catch (Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    private function saveRow(
        AdapterInterface $db,
        string $table,
        array $source,
        string $externalId,
        array $row,
        string $seen
    ): bool {
        $existing = $db->fetchRow($db->select()->from($table, [
            'id', 'status', 'finalized', 'last_seen_at', 'raw_attrs',
        ])->where('external_id = ?', $externalId)->forUpdate());

        $row['status'] = 'live';
        $row['last_seen_at'] = $seen;
        if ($row['end_time'] !== null) {
            $row['end_time'] = $this->utcDateTime(new DateTimeImmutable($row['end_time']));
        }
        if (!$existing) {
            $db->insert($table, $row + [
                'source' => isset(SourceSearches::DEFINITIONS[$source['domain']]['seller'])
                    ? $source['domain'] : $source['name'],
                'source_id' => $source['id'],
                'external_id' => $externalId,
                'first_seen' => $seen,
            ]);
            $this->enrichmentQueue->enqueue((string)$db->lastInsertId($table));
            return true;
        }

        if ((int)$existing['finalized'] === 1 || !in_array($existing['status'], self::REFRESHABLE_STATUSES, true)
            || ($existing['last_seen_at'] !== null && $existing['last_seen_at'] >= $seen)
        ) {
            return false;
        }

        foreach (['price_current', 'end_time', 'time_left_s'] as $field) {
            if ($row[$field] === null) {
                unset($row[$field]);
            }
        }
        if ($row['url'] === '') {
            unset($row['url']);
        }
        $attrs = json_decode((string)$existing['raw_attrs']);
        if (is_object($attrs)) {
            $incoming = (array)json_decode($row['raw_attrs'], false, 512, JSON_THROW_ON_ERROR);
            $row['raw_attrs'] = json_encode((object)array_replace((array)$attrs, $incoming), JSON_THROW_ON_ERROR);
        }
        $db->update($table, $row, ['id = ?' => $existing['id']]);
        return true;
    }

    private function assertDecimalPrices(AdapterInterface $db, string $listings, string $history): void
    {
        foreach ([$listings => ['price_current', 'price_bid_current'], $history => ['price']] as $table => $fields) {
            $columns = $db->describeTable($table);
            foreach ($fields as $field) {
                if ($columns[$field]['DATA_TYPE'] !== 'decimal' || (int)$columns[$field]['SCALE'] !== 2) {
                    throw new RuntimeException('Run Magento setup:upgrade before collecting decimal prices.');
                }
            }
        }
    }

    private function ensureStatuses(AdapterInterface $db): void
    {
        $table = $this->resource->getTableName('ghostfrog_ebay_listing_statuses');
        $statuses = [
            ['status' => 'live', 'lifecycle_stage' => 'live', 'is_live_like' => 1,
                'description' => 'Listing was last seen active on eBay.'],
            ['status' => 'unknown', 'lifecycle_stage' => 'pending_outcome', 'is_live_like' => 0,
                'description' => 'The current listing outcome is unconfirmed.'],
        ];
        $existing = $db->fetchCol($db->select()->from($table, 'status')->where('status IN (?)', ['live', 'unknown']));
        foreach ($statuses as $status) {
            if (!in_array($status['status'], $existing, true)) {
                $db->insertOnDuplicate($table, $status + [
                    'is_terminal' => 0,
                    'created_at' => new Expression('UTC_TIMESTAMP()'),
                ], ['status']);
            }
        }
    }

    private function utcDateTime(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
