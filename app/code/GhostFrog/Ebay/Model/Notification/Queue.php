<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Notification;

use GhostFrog\Ebay\Model\PriceRefresh\Settings;
use GhostFrog\Ebay\Model\ResourceModel\Listing;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

final class Queue
{
    public const TABLE = 'ghostfrog_ebay_opportunity_notification_queue';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Settings $settings,
        private readonly Offers $offers
    ) {
    }

    /** Called in the transaction that records the price and refreshes the opportunity. */
    public function observe(int $id): void
    {
        if ($this->settings->slackDestination() === null) {
            return;
        }

        $db = $this->resource->getConnection();
        $offers = $this->offers->forListing($id);
        foreach (['bin', 'auction'] as $type) {
            $key = ['listing_id' => $id, 'sale_type' => $type];
            if (!isset($offers[$type])) {
                $db->update($this->table(), ['status' => 'inactive'], $this->where($key) + [
                    'status IN (?)' => ['pending', 'sent', 'uncertain', 'failed'],
                ]);
            } elseif ($offers[$type]['fresh']) {
                $db->insertOnDuplicate($this->table(), $key + [
                    'status' => 'inactive', 'available_at' => new Expression('UTC_TIMESTAMP()'),
                ], ['listing_id']);
                // A previously delivered or pending alert remains in the same actionable episode.
                $this->transition($key, 'inactive', [
                    'status' => 'pending', 'attempts' => 0, 'available_at' => new Expression('UTC_TIMESTAMP()'),
                ]);
            }
        }
    }

    public function due(int $limit): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchAll($db->select()->from(['q' => $this->table()])
            ->join(['a' => $this->resource->getTableName(Listing::TABLE)], 'a.id = q.listing_id', [])
            ->where('q.status = ?', 'pending')->where('q.available_at <= UTC_TIMESTAMP()')
            ->order(new Expression("CASE WHEN q.sale_type = 'auction' THEN 0 ELSE 1 END ASC"))
            ->order(new Expression("CASE WHEN q.sale_type = 'auction' THEN a.end_time END ASC"))
            ->order(['q.available_at ASC', 'q.listing_id ASC'])->limit($limit));
    }

    public function lockListing(int $id): void
    {
        $db = $this->resource->getConnection();
        $db->fetchOne($db->select()->from($this->resource->getTableName(Listing::TABLE), 'id')
            ->where('id = ?', $id)->forUpdate());
    }

    public function transition(array $job, string $from, array $fields): bool
    {
        return (bool)$this->resource->getConnection()->update(
            $this->table(),
            $fields,
            $this->where($job) + ['status = ?' => $from]
        );
    }

    public function recoverInterrupted(): int
    {
        // Slack webhooks cannot confirm whether an interrupted request was accepted.
        return $this->resource->getConnection()->update(
            $this->table(),
            ['status' => 'uncertain'],
            ['status = ?' => 'sending']
        );
    }

    private function where(array $job): array
    {
        return ['listing_id = ?' => $job['listing_id'], 'sale_type = ?' => $job['sale_type']];
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
