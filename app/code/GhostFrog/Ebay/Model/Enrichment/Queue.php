<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Enrichment;

use GhostFrog\Ebay\Model\Condition\Settings;
use GhostFrog\Ebay\Model\ResourceModel\Source;
use Magento\Framework\App\ResourceConnection;
use Magento\MysqlMq\Model\QueueManagement;
use Magento\MysqlMq\Model\ResourceModel\Queue as MessageResource;
use Throwable;

final class Queue
{
    public const NAME = 'ghostfrog.ebay.enrichment';
    public const MODEL_KEY_QUEUE = 'ghostfrog.ebay.model_key';
    public const COMPLETED_KEY = '_enrichment_completed_at';
    private const STATUS_KEY = '_trading_enrichment_status';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly QueueManagement $queue,
        private readonly Settings $condition,
        private readonly MessageResource $messages
    ) {
    }

    public function enqueue(string $listingId, string $name = self::NAME): void
    {
        $db = $this->resource->getConnection();
        $queueTable = $this->resource->getTableName('queue');
        // The MySQL publisher and the listing store share Magento's default connection.
        // Register the queue on first use so that publishing always creates a delivery row.
        if (!$db->fetchOne($db->select()->from($queueTable, 'id')->where('name = ?', $name))) {
            $db->insertOnDuplicate($queueTable, ['name' => $name], ['name']);
        }
        $this->queue->addMessageToQueues($name, $listingId, [$name]);
    }

    /**
     * QueueManagement::readMessages() with deliveries for paused sources left untouched, so they
     * neither block the oldest-first queue nor use retries, and resume once the source is enabled.
     * Bodies that are not a stored listing ID are still claimed so the worker can reject them.
     */
    public function claim(int $limit, string $name = self::NAME): array
    {
        $db = $this->resource->getConnection();
        $select = $db->select()
            ->from(['queue_message' => $this->resource->getTableName('queue_message')], [
                QueueManagement::MESSAGE_TOPIC => 'topic_name',
                QueueManagement::MESSAGE_BODY => 'body',
            ])
            ->join(
                ['queue_message_status' => $this->resource->getTableName('queue_message_status')],
                'queue_message.id = queue_message_status.message_id',
                [
                    QueueManagement::MESSAGE_QUEUE_RELATION_ID => 'id',
                    QueueManagement::MESSAGE_QUEUE_ID => 'queue_id',
                    QueueManagement::MESSAGE_ID => 'message_id',
                    QueueManagement::MESSAGE_STATUS => 'status',
                    QueueManagement::MESSAGE_UPDATED_AT => 'updated_at',
                    QueueManagement::MESSAGE_NUMBER_OF_TRIALS => 'number_of_trials',
                ]
            )
            ->join(
                ['queue' => $this->resource->getTableName('queue')],
                'queue.id = queue_message_status.queue_id',
                [QueueManagement::MESSAGE_QUEUE_NAME => 'name']
            )
            ->joinLeft(
                ['listing' => $this->listingTable()],
                "listing.id = CAST(queue_message.body AS UNSIGNED) AND queue_message.body REGEXP '^[1-9][0-9]{0,18}$'",
                []
            )
            ->where('queue_message_status.status IN (?)', [
                QueueManagement::MESSAGE_STATUS_NEW,
                QueueManagement::MESSAGE_STATUS_RETRY_REQUIRED,
            ])
            ->where('queue.name = ?', $name)
            ->where('listing.id IS NULL OR ' . Source::activeListing($this->resource, 'listing'))
            ->order(['queue_message_status.updated_at ASC', 'queue_message_status.id ASC'])
            ->limit($limit);

        $selected = $db->fetchAll($select);
        // Another worker may take a delivery between the read and the update; keep only ours.
        $taken = array_flip($this->messages->takeMessagesInProgress(
            array_column($selected, QueueManagement::MESSAGE_QUEUE_RELATION_ID)
        ));
        $claimed = [];
        foreach ($selected as $message) {
            if (isset($taken[$message[QueueManagement::MESSAGE_QUEUE_RELATION_ID]])) {
                $message[QueueManagement::MESSAGE_STATUS] = QueueManagement::MESSAGE_STATUS_IN_PROGRESS;
                $claimed[] = $message;
            }
        }
        return $claimed;
    }

    /**
     * Queue unenriched listings that have no open delivery, up to $buffer waiting for active sources.
     * Only new listings are enqueued on save, so this picks up imported history and the listings of
     * a source that was switched back on. Sold listings come first because comps use them, then live
     * ones; unsold endings are skipped. eBay only returns items for about 90 days after they end.
     */
    public function topUp(int $buffer): int
    {
        $db = $this->resource->getConnection();
        $open = $db->select()
            ->from(['m' => $this->resource->getTableName('queue_message')], [
                'listing_id' => new \Magento\Framework\DB\Sql\Expression('CAST(m.body AS UNSIGNED)'),
            ])
            ->join(['s' => $this->resource->getTableName('queue_message_status')], 's.message_id = m.id', [])
            ->join(['q' => $this->resource->getTableName('queue')], 'q.id = s.queue_id', [])
            ->where('q.name = ?', self::NAME)
            ->where('s.status IN (?)', [
                QueueManagement::MESSAGE_STATUS_NEW,
                QueueManagement::MESSAGE_STATUS_IN_PROGRESS,
                QueueManagement::MESSAGE_STATUS_RETRY_REQUIRED,
            ]);
        $waiting = (int)$db->fetchOne($db->select()
            ->from(['o' => $open], new \Magento\Framework\DB\Sql\Expression('COUNT(*)'))
            ->join(['listing' => $this->listingTable()], 'listing.id = o.listing_id', [])
            ->where(Source::activeListing($this->resource, 'listing')));
        if ($waiting >= $buffer) {
            return 0;
        }

        $ids = $db->fetchCol($db->select()
            ->from(['a' => $this->listingTable()], 'id')
            ->joinLeft(['o' => $open], 'o.listing_id = a.id', [])
            ->where('o.listing_id IS NULL')
            ->where("JSON_EXTRACT(a.raw_attrs, '$." . self::STATUS_KEY . "') IS NULL")
            ->where("a.status IN ('sold', 'live', 'unknown')")
            ->where("a.external_id REGEXP '^([0-9]{1,20}|v[12][|][0-9]{1,20}[|]0)$'")
            ->where('COALESCE(a.end_time, a.last_seen_at) >= UTC_TIMESTAMP() - INTERVAL 90 DAY')
            ->where(Source::activeListing($this->resource))
            ->order([
                new \Magento\Framework\DB\Sql\Expression("a.status = 'sold' DESC"),
                new \Magento\Framework\DB\Sql\Expression("a.status = 'live' DESC"),
                new \Magento\Framework\DB\Sql\Expression('COALESCE(a.end_time, a.last_seen_at) ASC'),
                'a.id ASC',
            ])
            ->limit($buffer - $waiting));
        foreach ($ids as $id) {
            $this->enqueue((string)$id);
        }
        return count($ids);
    }

    public function acknowledge(int $relationId): void
    {
        $this->queue->changeStatus([$relationId], QueueManagement::MESSAGE_STATUS_COMPLETE);
    }

    public function retry(array $ids): void
    {
        if ($ids !== []) {
            $this->queue->changeStatus($ids, QueueManagement::MESSAGE_STATUS_RETRY_REQUIRED);
        }
    }

    public function failOrRetry(array $message): void
    {
        $id = (int)$message[QueueManagement::MESSAGE_QUEUE_RELATION_ID];
        if ((int)$message[QueueManagement::MESSAGE_NUMBER_OF_TRIALS] >= 4) {
            $this->queue->changeStatus([$id], QueueManagement::MESSAGE_STATUS_ERROR);
            return;
        }
        $this->queue->pushToQueueForRetry($id);
    }

    public function listing(string $id): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->listingTable(), ['external_id', 'raw_attrs'])
            ->where('id = ?', $id));
        if (!$row || $this->isComplete($row['raw_attrs'])) {
            return null;
        }
        return $row;
    }

    public function complete(int $relationId, string $listingId, ?array $result = null): void
    {
        $db = $this->resource->getConnection();
        $table = $this->listingTable();
        $db->beginTransaction();
        try {
            if ($result !== null) {
                $row = $db->fetchRow($db->select()->from($table, [
                    'raw_attrs', 'text_condition_checked', 'visual_condition_checked',
                ])
                    ->where('id = ?', $listingId)->forUpdate());
                if ($row && !$this->isComplete($row['raw_attrs'])) {
                    $existing = json_decode((string)$row['raw_attrs']);
                    $saved = is_object($existing) ? (array)$existing : [];
                    $raw = array_replace($saved, $result['raw'], [
                        self::STATUS_KEY => $result['status'],
                        '_trading_enriched_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    ]);
                    $success = in_array($result['status'], ['ok', 'no_attrs'], true);
                    $next = $success ? $this->condition->next(null, $row) : null;
                    if ($success && $next === null) {
                        $raw[self::COMPLETED_KEY] = gmdate('Y-m-d\TH:i:s\Z');
                    }
                    $db->update($table, $result['fields'] + [
                        'raw_attrs' => json_encode((object)$raw, JSON_THROW_ON_ERROR),
                    ], ['id = ?' => $listingId]);
                    if ($success) {
                        $this->enqueue($listingId, $next?->queue() ?? self::MODEL_KEY_QUEUE);
                    }
                }
            }
            $this->acknowledge($relationId);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    private function isComplete(?string $raw): bool
    {
        $attrs = json_decode((string)$raw);
        return is_object($attrs) && !empty($attrs->{self::STATUS_KEY});
    }

    private function listingTable(): string
    {
        return $this->resource->getTableName('ghostfrog_ebay_auction_listings');
    }
}
