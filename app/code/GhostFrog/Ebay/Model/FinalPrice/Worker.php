<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\FinalPrice;

use GhostFrog\Ebay\Model\Api\QuotaUnavailable;
use GhostFrog\Ebay\Model\Comps\Index;
use GhostFrog\Ebay\Model\Enrichment\TradingClient;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class Worker
{
    private const LOCK = 'ghostfrog_ebay_final_price';
    private const AUTH_COOLDOWN = 'ghostfrog_ebay_final_price_auth';
    private const BATCH_SIZE = 20;

    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly LockManagerInterface $lock,
        private readonly CacheInterface $cache,
        private readonly Queue $queue,
        private readonly TradingClient $client,
        private readonly Index $comps,
        private readonly LoggerInterface $logger
    ) {
    }

    public function enqueue(): void
    {
        if ($this->enabled() && $this->lock->lock(self::LOCK . '_enqueue', 0)) {
            try {
                $this->queue->fill(5 * self::BATCH_SIZE);
            } finally {
                $this->lock->unlock(self::LOCK . '_enqueue');
            }
        }
    }

    public function execute(): void
    {
        if (!$this->enabled() || !$this->lock->lock(self::LOCK, 0)) {
            return;
        }
        try {
            $canCheck = $this->config->isSetFlag('ghostfrog_ebay/ebay_api/gf_allow_ebay_api')
                && $this->client->configured() && !$this->cache->load(self::AUTH_COOLDOWN);
            $refresh = [];
            foreach ($this->queue->due(self::BATCH_SIZE) as $job) {
                $id = (string)$job['listing_id'];
                if ((bool)$job['needs_comp']) {
                    $refresh[] = $id;
                } elseif ($canCheck) {
                    try {
                        if ($this->check($id)) {
                            $refresh[] = $id;
                        }
                    } catch (QuotaUnavailable $exception) {
                        $this->logger->info($exception->getMessage());
                        $canCheck = false;
                    } catch (RuntimeException $exception) {
                        if (!in_array($exception->getCode(), [401, 403], true)) {
                            throw $exception;
                        }
                        $this->cache->save('1', self::AUTH_COOLDOWN, [], 3600);
                        $this->logger->warning('GhostFrog final-price checks paused after an eBay authentication error.');
                        $canCheck = false;
                    }
                }
            }
            if ($refresh !== []) {
                $this->comps->refreshSales($refresh);
                $this->queue->finish($refresh);
            }
        } finally {
            $this->lock->unlock(self::LOCK);
        }
    }

    private function enabled(): bool
    {
        return $this->config->isSetFlag('ghostfrog_ebay/general/enabled')
            && $this->config->isSetFlag('ghostfrog_ebay/heartbeat/gf_heartbeat_enable_close');
    }

    private function check(string $id): bool
    {
        $listing = $this->queue->listing($id);
        if ($listing === null) {
            $this->queue->finish([$id]);
            return false;
        }

        try {
            $result = $this->client->getSaleOutcome($listing['external_id']);
        } catch (QuotaUnavailable $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException && in_array($exception->getCode(), [401, 403], true)) {
                throw $exception;
            }
            $this->logger->warning('GhostFrog final-price check will retry listing {id}.', ['id' => $id]);
            $result = ['status' => 'retry', 'reason' => 'trading_check_failed'];
        }

        return $this->queue->record($id, $listing, $result);
    }
}
