<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\PriceRefresh;

use DateTimeImmutable;
use DateTimeZone;
use GhostFrog\Ebay\Model\Api\QuotaUnavailable;
use GhostFrog\Ebay\Model\Collection\BrowseClient;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class Worker
{
    private const LOCK = 'ghostfrog_ebay_price_refresh';

    public function __construct(
        private readonly Settings $settings,
        private readonly Queue $queue,
        private readonly BrowseClient $browse,
        private readonly Observation $observation,
        private readonly LockManagerInterface $lock,
        private readonly Pause $pause,
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
            $paused = $this->pause->state();
            if ($paused === null) {
                $deadline = hrtime(true) + 25_000_000_000;
                foreach ($this->queue->due($limit, $minute) as $job) {
                    if (hrtime(true) >= $deadline) {
                        break;
                    }
                    try {
                        ++$counts[$this->refresh((int)$job['listing_id'])];
                    } catch (QuotaUnavailable $exception) {
                        $paused = ['reason' => $exception->reason, 'retry_at' => $exception->retryAt];
                        break;
                    } catch (Throwable $exception) {
                        if ($exception instanceof RuntimeException && in_array($exception->getCode(), [401, 403], true)) {
                            $paused = $this->pause->holdAuthentication();
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
                $this->logger->info('GhostFrog price refresh batch.', ['queued' => $queued] + $counts + $metrics + [
                    'pause_reason' => $paused['reason'] ?? null,
                    'retry_at' => isset($paused['retry_at']) ? gmdate(DATE_ATOM, $paused['retry_at']) : null,
                ]);
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
}
