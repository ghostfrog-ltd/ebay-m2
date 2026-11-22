<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Enrichment;

use GhostFrog\Ebay\Model\Condition\Settings;
use Magento\Framework\App\ResourceConnection;
use Magento\MysqlMq\Model\QueueManagement;
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
        private readonly Settings $condition
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

    public function claim(int $limit, string $name = self::NAME): array
    {
        return $this->queue->readMessages($name, $limit);
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
                $row = $db->fetchRow($db->select()->from($table, ['raw_attrs'])
                    ->where('id = ?', $listingId)->forUpdate());
                if ($row && !$this->isComplete($row['raw_attrs'])) {
                    $existing = json_decode((string)$row['raw_attrs']);
                    $saved = is_object($existing) ? (array)$existing : [];
                    $raw = array_replace($saved, $result['raw'], [
                        self::STATUS_KEY => $result['status'],
                        '_trading_enriched_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    ]);
                    $success = in_array($result['status'], ['ok', 'no_attrs'], true);
                    $next = $success ? $this->condition->next() : null;
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
