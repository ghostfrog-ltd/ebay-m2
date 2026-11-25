<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Enrichment;

use GhostFrog\Ebay\Model\Api\QuotaUnavailable;
use GhostFrog\Ebay\Model\Api\DailyQuota;
use GhostFrog\Ebay\Model\Api\Usage;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\MysqlMq\Model\QueueManagement;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class Worker
{
    private const LOCK = 'ghostfrog_ebay_enrichment';
    private const COOLDOWN = 'ghostfrog_ebay_enrichment_ebay_cooldown';

    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly LockManagerInterface $lock,
        private readonly CacheInterface $cache,
        private readonly Queue $queue,
        private readonly TradingClient $client,
        private readonly Usage $usage,
        private readonly DailyQuota $quota,
        private readonly LoggerInterface $logger
    ) {
    }

    /** Keeps five batches waiting so imported and re-enabled listings are enriched without manual requeues. */
    public function enqueue(): void
    {
        if (!$this->config->isSetFlag('ghostfrog_ebay/general/enabled')
            || !$this->config->isSetFlag('ghostfrog_ebay/heartbeat/gf_heartbeat_enable_attrs')
            || !$this->lock->lock(self::LOCK . '_enqueue', 0)
        ) {
            return;
        }
        try {
            $this->queue->topUp(5 * $this->batchSize());
        } finally {
            $this->lock->unlock(self::LOCK . '_enqueue');
        }
    }

    private function batchSize(): int
    {
        return max(1, (int)($this->config->getValue('ghostfrog_ebay/listings/gf_attr_backfill_limit') ?? 20));
    }

    public function execute(): void
    {
        if (!$this->config->isSetFlag('ghostfrog_ebay/general/enabled')
            || !$this->config->isSetFlag('ghostfrog_ebay/heartbeat/gf_heartbeat_enable_attrs')
            || !$this->config->isSetFlag('ghostfrog_ebay/ebay_api/gf_allow_ebay_api')
            || !$this->client->configured()
            || $this->cache->load(self::COOLDOWN)
            || !$this->lock->lock(self::LOCK, 0)
        ) {
            return;
        }

        $unprocessed = [];
        try {
            $this->quota->refreshIfDue(Usage::TRADING);
            $limit = min($this->batchSize(),$this->usage->remaining(Usage::TRADING));
            if ($limit <= 0) {
                return;
            }
            $messages = $this->queue->claim($limit);
            $unprocessed = array_fill_keys(array_column($messages, QueueManagement::MESSAGE_QUEUE_RELATION_ID), true);
            foreach ($messages as $message) {
                $id = (int)$message[QueueManagement::MESSAGE_QUEUE_RELATION_ID];
                $body = (string)$message[QueueManagement::MESSAGE_BODY];
                try {
                    if (!ctype_digit($body) || $body === '0') {
                        throw new RuntimeException('Invalid listing ID in enrichment queue.', 422);
                    }
                    $listing = $this->queue->listing($body);
                    $result = $listing === null ? null : $this->client->getItem($listing['external_id']);
                    $this->queue->complete($id, $body, $result);
                } catch (QuotaUnavailable $exception) {
                    $this->logger->warning('{message}', ['message' => $exception->getMessage()]);
                    break;
                } catch (Throwable $e) {
                    $code = get_class($e) === RuntimeException::class ? $e->getCode() : 0;
                    if (in_array($code, [401, 403], true)) {
                        $this->cache->save('1', self::COOLDOWN, [], 3600);
                        $this->logger->warning('GhostFrog Trading enrichment paused after eBay response {code}.', ['code' => $code]);
                        break;
                    }
                    $this->queue->failOrRetry($message);
                    $this->logger->warning('GhostFrog Trading enrichment failed for delivery {id}: {error}', [
                        'id' => $id,
                        'error' => $e->getMessage(),
                    ]);
                }
                unset($unprocessed[$id]);
            }
        } finally {
            try {
                $this->queue->retry(array_keys($unprocessed));
            } finally {
                $this->lock->unlock(self::LOCK);
            }
        }
    }
}
