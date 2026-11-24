<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Condition;

use GhostFrog\Ebay\Model\Opportunities\Rows;
use GhostFrog\Ebay\Model\ResourceModel\Listing;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\MysqlMq\Model\QueueManagement;
use Magento\MysqlMq\Model\ResourceModel\Queue;
use RuntimeException;
use Throwable;

final class Backfill
{
    private const LOCK = 'ghostfrog_ebay_condition_backfill';
    private const BATCH_SIZE = 5000;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LockManagerInterface $lock,
        private readonly Queue $queue
    ) {
    }

    /** @return array<string, int> Jobs added for each stage. */
    public function run(?int $listingId = null): array
    {
        if (!$this->lock->lock(self::LOCK, 0)) {
            throw new RuntimeException('A condition backfill is already being queued.');
        }
        try {
            $db = $this->resource->getConnection();
            $listings = $db->select()->from($this->resource->getTableName(Listing::TABLE), 'id');
            if ($listingId !== null) {
                $listings->where('id = ?', $listingId);
            }
            $ids = $db->fetchCol($listings);
            rsort($ids, SORT_NUMERIC);
            $priority = $listingId === null ? $db->fetchCol(
                $db->select()->from($this->resource->getTableName(Rows::TABLE), 'listing_id')
                    ->where('eligible_until > UTC_TIMESTAMP()')->where('listing_id <= ?', $ids[0] ?? 0)
                    ->order('listing_id DESC')
            ) : [];
            $queued = [];
            foreach (Stage::cases() as $stage) {
                $queued[$stage->value] = $this->enqueueStage($stage, $ids, $priority, $listingId);
            }
            return $queued;
        } finally {
            $this->lock->unlock(self::LOCK);
        }
    }

    private function enqueueStage(Stage $stage, array $ids, array $priority, ?int $listingId): int
    {
        $db = $this->resource->getConnection();
        $graded = $db->select()->from($this->resource->getTableName($stage->assessmentTable()), 'listing_id')
            ->distinct()->where(Assessment::modelResultCondition($db, $stage));
        if ($listingId !== null) {
            $graded->where('listing_id = ?', $listingId);
        }
        $excluded = $this->pending($stage, $listingId) + array_fill_keys($db->fetchCol($graded), true);

        $queued = 0;
        foreach (array_chunk($priority, self::BATCH_SIZE) as $batch) {
            $queued += $this->publish($stage, $batch, $excluded);
        }
        $excluded += array_fill_keys($priority, true);

        foreach (array_chunk($ids, self::BATCH_SIZE) as $batch) {
            $queued += $this->publish($stage, $batch, $excluded);
        }
        return $queued;
    }

    private function pending(Stage $stage, ?int $listingId): array
    {
        $db = $this->resource->getConnection();
        $select = $db->select()->from(['delivery' => $this->resource->getTableName('queue_message_status')], [])
            ->join(['queue' => $this->resource->getTableName('queue')], 'queue.id = delivery.queue_id', [])
            ->join(['message' => $this->resource->getTableName('queue_message')], 'message.id = delivery.message_id', 'body')
            ->where('queue.name IN (?)', [$stage->queue(), $stage->backfillQueue()])
            ->where('delivery.status IN (?)', [
                QueueManagement::MESSAGE_STATUS_NEW,
                QueueManagement::MESSAGE_STATUS_IN_PROGRESS,
                QueueManagement::MESSAGE_STATUS_RETRY_REQUIRED,
            ]);
        if ($listingId !== null) {
            $select->where('message.body = ?', (string)$listingId);
        }
        return array_fill_keys($db->fetchCol($select), true);
    }

    private function publish(Stage $stage, array $ids, array $pending): int
    {
        $jobs = [];
        foreach ($ids as $id) {
            if (!isset($pending[$id])) {
                $jobs[] = (string)$id;
            }
        }
        if ($jobs === []) {
            return 0;
        }

        $db = $this->resource->getConnection();
        $name = $stage->backfillQueue();
        $queueTable = $this->resource->getTableName('queue');
        $db->beginTransaction();
        try {
            $db->insertOnDuplicate($queueTable, ['name' => $name], ['name']);
            $messageIds = $this->queue->saveMessages($name, $jobs);
            $this->queue->linkMessagesWithQueues($messageIds, [$name]);
            // The backlog can exceed Magento's lifetime for new messages.
            $db->update(
                $this->resource->getTableName('queue_message_status'),
                ['status' => QueueManagement::MESSAGE_STATUS_RETRY_REQUIRED],
                ['message_id IN (?)' => $messageIds]
            );
            $db->commit();
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        }
        return count($jobs);
    }
}
