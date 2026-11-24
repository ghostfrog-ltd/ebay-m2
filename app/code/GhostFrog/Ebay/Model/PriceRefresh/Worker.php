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
        private readonly Candidates $candidates,
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
            $candidateCounts = [
                'scanned' => 0, 'eligible' => 0, 'excluded' => [], 'queued' => 0, 'attempted' => 0,
                'refreshed' => 0, 'qualified' => 0, 'rejected_after_refresh' => 0, 'unavailable' => 0,
                'superseded' => 0, 'quota_deferrals' => 0, 'authentication_deferrals' => 0, 'failed' => 0, 'retry' => 0,
            ];
            $queued = $this->queue->enqueueDue($limit, $minute);
            $paused = $this->pause->state();
            if ($paused === null) {
                $deadline = hrtime(true) + 25_000_000_000;
                $jobs = $this->queue->due($limit, $minute);
                foreach ($jobs as $job) {
                    if (hrtime(true) >= $deadline) {
                        break;
                    }
                    try {
                        if (!empty($job['candidate_observation'])) {
                            $this->applyCandidate($job['candidate_observation'], $candidateCounts);
                        } else {
                            ++$counts[$this->refresh((int)$job['listing_id'])];
                        }
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
                if ($paused === null && $this->settings->prequalificationEnabled() && count($jobs) < $limit
                    && hrtime(true) < $deadline && $this->queue->due(1, $minute) === []) {
                    $paused = $this->prequalify($deadline, $candidateCounts);
                }
            }
            if ($candidateCounts['scanned'] || $candidateCounts['attempted'] || $candidateCounts['refreshed']
                || $candidateCounts['superseded'] || $candidateCounts['quota_deferrals']
                || $candidateCounts['authentication_deferrals'] || $candidateCounts['failed'] || $candidateCounts['retry']
                || $candidateCounts['excluded'] !== []) {
                $this->logger->info('GhostFrog auction prequalification batch.', $candidateCounts + [
                    'browse_calls' => $candidateCounts['attempted'],
                    'pause_reason' => $paused['reason'] ?? null,
                    'retry_at' => isset($paused['retry_at']) ? gmdate(DATE_ATOM, $paused['retry_at']) : null,
                ]);
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

    private function prequalify(int $deadline, array &$counts): ?array
    {
        $batch = $this->settings->candidateBatch();
        $slots = $this->queue->candidateSlots($batch);
        if ($slots > 0) {
            $selection = $this->candidates->scan($slots, advance: true);
            $counts = array_replace($counts, array_intersect_key($selection, $counts));
            $counts['queued'] = $this->queue->enqueueCandidates($selection['selected']);
        }

        foreach ($this->queue->dueCandidates($batch) as $job) {
            if (hrtime(true) >= $deadline) {
                break;
            }
            $id = (int)$job['listing_id'];
            $startedAt = gmdate('Y-m-d H:i:s');
            $reserved = false;
            $observation = $job['candidate_observation'];
            try {
                if ($observation === null) {
                    $snapshot = $this->queue->candidateSnapshot($id);
                    $reason = $snapshot === null ? 'missing_listing' : $this->candidates->rejection($id);
                    if ($reason !== null) {
                        $this->rejectCandidate($id, $reason, $counts);
                        continue;
                    }
                    $item = $this->browse->getItem(
                        $snapshot['external_id'],
                        $this->settings->candidateBudget(),
                        $this->settings->refreshBatch(),
                        function () use ($id, $snapshot, $startedAt, &$reserved): void {
                            if ($this->candidates->rejection($id) !== null) {
                                throw new CandidateChanged();
                            }
                            $this->queue->beginCandidateAttempt($id, $snapshot, $startedAt);
                            $reserved = true;
                        }
                    );
                    $fields = $this->fields($snapshot['external_id'], $item);
                    $observation = $this->queue->saveCandidateObservation($id, $snapshot, $fields, $startedAt);
                }
                $this->applyCandidate($observation, $counts);
            } catch (CandidateChanged) {
                $this->rejectCandidate($id, 'changed_before_dispatch', $counts);
            } catch (QuotaUnavailable $exception) {
                if ($reserved) {
                    $this->queue->releaseCandidateAttempt($id, $startedAt);
                }
                ++$counts['quota_deferrals'];
                return ['reason' => $exception->reason, 'retry_at' => $exception->retryAt];
            } catch (Throwable $exception) {
                if ($exception instanceof RuntimeException && in_array($exception->getCode(), [401, 403], true)) {
                    if ($reserved) {
                        $this->queue->releaseCandidateAttempt($id, $startedAt);
                    }
                    ++$counts['authentication_deferrals'];
                    return $this->pause->holdAuthentication();
                }
                if ($reserved && $observation === null) {
                    // An unsuccessful or uncertain dispatch still uses this listing's one check.
                    $this->queue->finish($id);
                    ++$counts['failed'];
                } else {
                    $this->queue->retry($job);
                    ++$counts['retry'];
                }
                $this->logger->warning('GhostFrog auction prequalification failed for listing {listing_id} ({type}).', [
                    'listing_id' => $id, 'type' => get_class($exception),
                ]);
            } finally {
                $counts['attempted'] += (int)$reserved;
            }
        }
        return null;
    }

    private function rejectCandidate(int $id, string $reason, array &$counts): void
    {
        $this->queue->finish($id);
        $counts['excluded'][$reason] = ($counts['excluded'][$reason] ?? 0) + 1;
    }

    private function applyCandidate(string $observation, array &$counts): void
    {
        [$snapshot, $fields, $startedAt] = json_decode($observation, true, flags: JSON_THROW_ON_ERROR);
        $id = (int)$snapshot['id'];
        if (!$this->queue->record($id, $snapshot, $fields, $startedAt)) {
            ++$counts['superseded'];
            return;
        }
        ++$counts['refreshed'];
        if ($fields['status'] !== 'live') {
            ++$counts['unavailable'];
        }
        ++$counts[$this->queue->listing($id) === null ? 'rejected_after_refresh' : 'qualified'];
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
        $fields = $this->fields($listing['external_id'], $item);
        if (!$this->queue->record($id, $listing, $fields, $startedAt)) {
            return 'discarded';
        }
        return $fields['status'] === 'live' ? 'updated' : 'unavailable';
    }

    private function fields(string $externalId, ?array $item): array
    {
        return $this->observation->fields($externalId, $item, new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }
}
