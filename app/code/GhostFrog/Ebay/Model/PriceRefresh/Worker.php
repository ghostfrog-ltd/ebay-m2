<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\PriceRefresh;

use DateTimeImmutable;
use DateTimeZone;
use GhostFrog\Ebay\Model\Api\QuotaUnavailable;
use GhostFrog\Ebay\Model\Collection\BrowseClient;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class Worker
{
    private const LOCK = 'ghostfrog_ebay_price_refresh';
    private const PAUSE = 'ghostfrog_ebay_price_refresh_pause';

    public function __construct(
        private readonly Settings $settings,
        private readonly Queue $queue,
        private readonly BrowseClient $browse,
        private readonly Observation $observation,
        private readonly LockManagerInterface $lock,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->settings->enabled() || !$this->lock->lock(self::LOCK, 0)) {
            return;
        }
        try {
            $limit = $this->settings->refreshBatch();
            $minute = intdiv(time(), 60);
            $counts = ['updated' => 0, 'unavailable' => 0, 'discarded' => $this->queue->discardObsolete($limit), 'retry' => 0];
            $queued = $this->queue->enqueueDue($limit, $minute);
            if (!$this->cache->load(self::PAUSE)) {
                $deadline = hrtime(true) + 25_000_000_000;
                foreach ($this->queue->due($limit, $minute) as $job) {
                    if (hrtime(true) >= $deadline) {
                        break;
                    }
                    try {
                        ++$counts[$this->refresh((int)$job['listing_id'])];
                    } catch (QuotaUnavailable $exception) {
                        $this->pause($exception->retryAt);
                        $this->logger->info($exception->getMessage());
                        break;
                    } catch (Throwable $exception) {
                        if ($exception instanceof RuntimeException && in_array($exception->getCode(), [401, 403], true)) {
                            $this->pause(time() + 3600);
                            $this->logger->warning('GhostFrog price refresh paused after eBay authentication failure.');
                            break;
                        }
                        $this->queue->retry($job);
                        ++$counts['retry'];
                        $this->logger->warning('GhostFrog price refresh will retry listing {listing_id} ({type}).', [
                            'listing_id' => $job['listing_id'], 'type' => get_class($exception),
                        ]);
                    }
                }
            }
            $metrics = $this->queue->metrics();
            if ($queued || array_sum($counts) || (int)$metrics['depth'] || (int)$metrics['stale']) {
                $this->logger->info('GhostFrog price refresh batch.', ['queued' => $queued] + $counts + $metrics);
            }
        } finally {
            $this->lock->unlock(self::LOCK);
        }
    }

    private function refresh(int $id): string
    {
        $listing = $this->queue->listing($id);
        if ($listing === null) {
            $this->queue->finish($id);
            return 'discarded';
        }
        $startedAt = gmdate('Y-m-d H:i:s');
        $item = $this->browse->getItem($listing['external_id']);
        $fields = $this->observation->fields(
            $listing['external_id'],
            $item,
            new DateTimeImmutable('now', new DateTimeZone('UTC'))
        );
        if (!$this->queue->record($id, $listing, $fields, $startedAt)) {
            return 'discarded';
        }
        return $fields['status'] === 'live' ? 'updated' : 'unavailable';
    }

    private function pause(int $until): void
    {
        $this->cache->save('1', self::PAUSE, [], max(1, $until - time()));
    }
}
