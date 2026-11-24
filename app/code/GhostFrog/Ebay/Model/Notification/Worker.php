<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Notification;

use GhostFrog\Ebay\Model\Indexing\Transaction;
use GhostFrog\Ebay\Model\PriceRefresh\Queue as Refreshes;
use GhostFrog\Ebay\Model\PriceRefresh\Settings;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class Worker
{
    private const LOCK = 'ghostfrog_ebay_opportunity_notifications';
    private const THROTTLE = 'ghostfrog_ebay_opportunity_slack_throttle';

    public function __construct(
        private readonly Settings $settings,
        private readonly Queue $queue,
        private readonly Offers $offers,
        private readonly Refreshes $refreshes,
        private readonly Transaction $transaction,
        private readonly Slack $slack,
        private readonly LockManagerInterface $lock,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $url = $this->settings->slackDestination();
        if ($url === null || $this->cache->load(self::THROTTLE) || !$this->lock->lock(self::LOCK, 0)) {
            return;
        }
        try {
            $recovered = $this->queue->recoverInterrupted();
            if ($recovered) {
                $this->logger->warning('GhostFrog recovered {count} alerts with unknown Slack delivery.', ['count' => $recovered]);
            }
            $deadline = hrtime(true) + 20_000_000_000;
            foreach ($this->queue->due($this->settings->notificationBatch()) as $job) {
                if (hrtime(true) >= $deadline || $this->cache->load(self::THROTTLE)) {
                    break;
                }
                $this->process($url, $job);
            }
        } finally {
            $this->lock->unlock(self::LOCK);
        }
    }

    private function process(string $url, array $job): void
    {
        $id = (int)$job['listing_id'];
        $type = $job['sale_type'];
        $offer = null;
        $outcome = 'suppressed';
        $this->transaction->run(function () use ($id, $type, $job, &$offer, &$outcome): void {
            $this->queue->lockListing($id);
            $current = $this->offers->forListing($id)[$type] ?? null;
            if ($current === null) {
                $this->queue->transition($job, 'pending', ['status' => 'inactive']);
            } elseif (!$current['fresh']) {
                if ($this->settings->enabled()) {
                    $this->refreshes->request($id);
                }
                $this->queue->transition($job, 'pending', ['available_at' => gmdate('Y-m-d H:i:s', time() + 60)]);
                $outcome = 'deferred for a fresh price';
            } elseif ($this->queue->transition($job, 'pending', ['status' => 'sending'])) {
                $offer = $current;
            }
        });
        if ($offer !== null) {
            $outcome = $this->deliver($url, $job, $offer);
        }
        $this->logger->info('GhostFrog {sale_type} alert for {listing_id} {outcome}.', [
            'sale_type' => $type, 'listing_id' => $id, 'outcome' => $outcome,
        ]);
    }

    private function deliver(string $url, array $job, array $offer): string
    {
        $fields = ['status' => 'uncertain'];
        try {
            [$status, $retryAfter] = $this->slack->send($url, $job['sale_type'], $offer);
            $attempts = (int)$job['attempts'] + 1;
            $delay = $retryAfter ?? min(900, 60 * 2 ** min($attempts - 1, 4));
            if ($status === 429) {
                $this->cache->save('1', self::THROTTLE, [], $delay);
            }
            $fields = match (true) {
                $status === 200 => ['status' => 'sent'],
                ($status === 429 || $status >= 500) && $attempts < 5 => [
                    'status' => 'pending', 'attempts' => $attempts,
                    'available_at' => gmdate('Y-m-d H:i:s', time() + $delay),
                ],
                default => ['status' => 'failed'],
            };
        } catch (Throwable $exception) {
            // A transport error can follow delivery. Avoid replaying an ambiguous request.
            $this->logger->warning('GhostFrog Slack delivery outcome is unknown ({type}).', [
                'type' => get_class($exception),
            ]);
        }

        $this->queue->transition($job, 'sending', $fields);
        return $fields['status'] === 'pending' ? 'will retry' : $fields['status'];
    }
}
