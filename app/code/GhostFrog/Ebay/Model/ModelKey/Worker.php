<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey;

use GhostFrog\Ebay\Model\Enrichment\Queue;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\MysqlMq\Model\QueueManagement;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class Worker
{
    private const LOCK = 'ghostfrog_ebay_model_key';
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly ResourceConnection $resource,
        private readonly LockManagerInterface $lock,
        private readonly Queue $queue,
        private readonly Classifier $classifier,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isSetFlag('ghostfrog_ebay/general/enabled') || !$this->lock->lock(self::LOCK, 0)) {
            return;
        }
        $unprocessed = [];
        try {
            $messages = $this->queue->claim(self::BATCH_SIZE, Queue::MODEL_KEY_QUEUE);
            $unprocessed = array_fill_keys(array_column($messages, QueueManagement::MESSAGE_QUEUE_RELATION_ID), true);
            foreach ($messages as $message) {
                $delivery = (int)$message[QueueManagement::MESSAGE_QUEUE_RELATION_ID];
                $listingId = (string)$message[QueueManagement::MESSAGE_BODY];
                try {
                    if (!ctype_digit($listingId) || $listingId === '0') {
                        throw new RuntimeException('Invalid listing ID in model-key queue.');
                    }
                    $this->applyKey($delivery, $listingId);
                } catch (Throwable $exception) {
                    $this->queue->failOrRetry($message);
                    $this->logger->warning('GhostFrog model-key generation failed for delivery {id}.', ['id' => $delivery]);
                }
                unset($unprocessed[$delivery]);
            }
        } finally {
            try {
                $this->queue->retry(array_keys($unprocessed));
            } finally {
                $this->lock->unlock(self::LOCK);
            }
        }
    }

    private function applyKey(int $delivery, string $listingId): void
    {
        $db = $this->resource->getConnection();
        $listings = $this->resource->getTableName('ghostfrog_ebay_auction_listings');
        $sources = $this->resource->getTableName('ghostfrog_ebay_sources');
        $db->beginTransaction();
        try {
            $listing = $db->fetchRow($db->select()->from($listings, [
                'source', 'source_id', 'title', 'model_key', 'raw_attrs',
                'brand', 'model_name', 'storage_gb', 'colour', 'epid',
            ])->where('id = ?', $listingId)->forUpdate());
            // An early key from collection is recomputed with the full evidence and its pending mark cleared.
            $pending = $listing && Pending::is($listing['raw_attrs']);
            if ($listing && ($pending
                || in_array(strtolower(trim((string)$listing['model_key'])), ['', 'unknown'], true))
            ) {
                $domain = $db->fetchOne($db->select()->from($sources, 'domain')->where('id = ?', $listing['source_id']));
                $update = ['model_key' => $this->classifier->forListing((string)($domain ?: $listing['source']), $listing)];
                if ($pending) {
                    $update['raw_attrs'] = new Expression("JSON_REMOVE(raw_attrs, '" . Pending::PATH . "')");
                }
                $db->update($listings, $update, ['id = ?' => $listingId]);
            }
            $this->queue->acknowledge($delivery);
            $db->commit();
        } catch (Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }
}
