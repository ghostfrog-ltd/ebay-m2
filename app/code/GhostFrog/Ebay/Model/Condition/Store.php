<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Condition;

use GhostFrog\Ebay\Model\Enrichment\Queue;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\MysqlMq\Model\QueueManagement;
use RuntimeException;
use Throwable;

final class Store
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Queue $queue,
        private readonly Settings $settings
    ) {
    }

    public function listing(string $id, Stage $stage): ?array
    {
        $listing = $this->find($this->resource->getConnection(), $id, false);
        return $this->needsCheck($listing, $stage) ? $listing : null;
    }

    /**
     * Record the assessment, publish its successor, and acknowledge the message
     * together. A disabled stage advances without marking it as checked.
     */
    public function complete(Stage $stage, int $delivery, string $id, ?array $evidence, ?array $assessment): bool
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('ghostfrog_ebay_auction_listings');
        $db->beginTransaction();
        try {
            $listing = $this->find($db, $id, true);
            $needed = $this->needsCheck($listing, $stage);
            if ($needed) {
                if ($assessment !== null && ($evidence === null || $listing['title'] !== $evidence['title']
                    || $listing['raw_attrs'] !== $evidence['raw_attrs'])
                ) {
                    throw new RuntimeException('Listing evidence changed while checking condition.');
                }

                $attrs = $this->attrs($listing);
                $update = [];
                if ($assessment !== null) {
                    $this->saveAssessment($db, $stage, $listing, $assessment);
                    $field = $stage->value . '_condition_checked';
                    $update[$field] = 1;
                    $update[$field . '_at'] = new Expression('UTC_TIMESTAMP()');
                } else {
                    $attrs[$this->skippedMarker($stage)] = true;
                }

                $next = $this->settings->next($stage);
                if ($next === null) {
                    $attrs[Queue::COMPLETED_KEY] = gmdate('Y-m-d\TH:i:s\Z');
                    $update['condition_grade'] = Assessment::effective($listing['condition_grade'], $this->checks($db, $id));
                }
                $update['raw_attrs'] = json_encode((object)$attrs, JSON_THROW_ON_ERROR);
                $db->update($table, $update, ['id = ?' => $id]);
                $this->queue->enqueue($id, $next?->queue() ?? Queue::MODEL_KEY_QUEUE);
            }
            $this->queue->acknowledge($delivery);
            $db->commit();
            return $needed;
        } catch (Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    public function backlog(Stage $stage): array
    {
        $db = $this->resource->getConnection();
        $select = $db->select()->from(
            ['delivery' => $this->resource->getTableName('queue_message_status')],
            [
                'waiting' => new Expression('COUNT(*)'),
                'oldest_wait_seconds' => new Expression('TIMESTAMPDIFF(SECOND, MIN(delivery.updated_at), NOW())'),
            ]
        )->join(
            ['queue' => $this->resource->getTableName('queue')],
            'queue.id = delivery.queue_id',
            []
        )->where('queue.name = ?', $stage->queue())
            ->where('delivery.status IN (?)', [
                QueueManagement::MESSAGE_STATUS_NEW,
                QueueManagement::MESSAGE_STATUS_RETRY_REQUIRED,
            ]);
        $row = $db->fetchRow($select);
        return ['waiting' => (int)$row['waiting'], 'oldest_wait_seconds' => (int)$row['oldest_wait_seconds']];
    }

    private function find(AdapterInterface $db, string $id, bool $lock): ?array
    {
        $table = $this->resource->getTableName('ghostfrog_ebay_auction_listings');
        $select = $db->select()->from($table, [
            'id', 'external_id', 'title', 'raw_attrs', 'condition_grade',
            'text_condition_checked', 'visual_condition_checked',
        ])->where('id = ?', $id);
        if ($lock) {
            $select->forUpdate();
        }
        $row = $db->fetchRow($select);
        return $row ?: null;
    }

    private function needsCheck(?array $listing, Stage $stage): bool
    {
        if ($listing === null || $listing[$stage->value . '_condition_checked']) {
            return false;
        }
        $attrs = $this->attrs($listing);
        return empty($attrs[Queue::COMPLETED_KEY]) && empty($attrs[$this->skippedMarker($stage)]);
    }

    private function attrs(array $listing): array
    {
        $attrs = json_decode((string)$listing['raw_attrs'], true);
        return is_array($attrs) ? $attrs : [];
    }

    private function skippedMarker(Stage $stage): string
    {
        return '_enrichment_' . $stage->value . '_skipped';
    }

    private function saveAssessment(AdapterInterface $db, Stage $stage, array $listing, array $result): void
    {
        $gradeField = $stage->gradeField();
        $flagsField = $stage->flagsField();
        $assessment = $result['assessment'];
        $data = [
            'listing_id' => $listing['id'],
            'external_id' => $listing['external_id'],
            'model_name' => $result['model'],
            'assessment' => json_encode($assessment, JSON_THROW_ON_ERROR),
            $gradeField => $assessment[$gradeField],
            $flagsField => json_encode($assessment[$flagsField], JSON_THROW_ON_ERROR),
            'confidence' => $assessment['confidence'],
            'review_required' => (int)$assessment['review_required'],
            'checked_at' => new Expression('UTC_TIMESTAMP()'),
        ];
        if ($stage === Stage::Visual) {
            $data['image_urls'] = json_encode($result['urls'], JSON_THROW_ON_ERROR);
        }
        $db->insert($this->assessmentTable($stage), $data);
    }

    private function checks(AdapterInterface $db, string $id): array
    {
        $checks = [];
        foreach (Stage::cases() as $stage) {
            $row = $db->fetchRow($db->select()->from($this->assessmentTable($stage), [$stage->gradeField(), 'review_required'])
                ->where('listing_id = ?', $id)->order(['checked_at DESC', 'id DESC'])->limit(1));
            if ($row) {
                $checks[] = ['grade' => $row[$stage->gradeField()], 'review_required' => (bool)$row['review_required']];
            }
        }
        return $checks;
    }

    private function assessmentTable(Stage $stage): string
    {
        return $this->resource->getTableName('ghostfrog_ebay_listing_' . $stage->value . '_conditions');
    }
}
