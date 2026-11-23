<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Condition;

use GhostFrog\Ebay\Model\Enrichment\Queue;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\MysqlMq\Model\QueueManagement;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class Worker
{
    private const LOCK = 'ghostfrog_ebay_condition';
    private const START_WAVES_FOR_SECONDS = 25;
    private const SKIP_BATCH_SIZE = 500;
    private const SKIP_WAVE_SIZE = 100;

    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly LockManagerInterface $lock,
        private readonly Settings $settings,
        private readonly Queue $queue,
        private readonly Store $store,
        private readonly OllamaClient $ollama,
        private readonly ImageLoader $photos,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isSetFlag('ghostfrog_ebay/general/enabled') || !$this->lock->lock(self::LOCK, 0)) {
            return;
        }
        try {
            foreach (Stage::cases() as $stage) {
                $this->runStage($stage);
            }
        } finally {
            $this->lock->unlock(self::LOCK);
        }
    }

    private function runStage(Stage $stage): void
    {
        $started = hrtime(true);
        $enabled = $this->settings->enabled($stage);
        $limit = $enabled ? $this->settings->batchSize() : self::SKIP_BATCH_SIZE;
        $waveSize = $enabled ? $this->settings->concurrency($stage) : self::SKIP_WAVE_SIZE;
        $counts = ['claimed' => 0, 'assessed' => 0, 'skipped' => 0, 'stale' => 0, 'failed' => 0, 'deferred' => 0];
        $pending = [];
        $failures = [];
        try {
            do {
                $size = min($waveSize, $limit - $counts['claimed']);
                $messages = $this->queue->claim($size, $stage->queue());
                $counts['claimed'] += count($messages);
                $pending += array_column($messages, null, QueueManagement::MESSAGE_QUEUE_RELATION_ID);
                $this->processWave($stage, $enabled, $messages, $pending, $failures, $counts);
            } while (count($messages) === $size && $counts['claimed'] < $limit && $counts['deferred'] === 0
                && hrtime(true) - $started < self::START_WAVES_FOR_SECONDS * 1_000_000_000);
        } finally {
            try {
                foreach ($failures as $delivery => $message) {
                    $this->queue->failOrRetry($message);
                    unset($pending[$delivery]);
                }
            } finally {
                // Hold retries until the stage exits so a delivery is attempted at most once per run.
                $this->queue->retry(array_keys($pending));
            }
        }

        if ($counts['claimed'] > 0) {
            $seconds = (hrtime(true) - $started) / 1_000_000_000;
            $this->logger->info('GhostFrog {stage} condition batch.', $counts + $this->store->backlog($stage) + [
                'stage' => $stage->value,
                'seconds' => round($seconds, 3),
                'listings_per_minute' => round(60 * ($counts['assessed'] + $counts['skipped']) / $seconds, 1),
                'failure_percent' => round(100 * ($counts['failed'] + $counts['deferred']) / $counts['claimed'], 2),
            ]);
        }
    }

    private function processWave(
        Stage $stage,
        bool $enabled,
        array $messages,
        array &$pending,
        array &$failures,
        array &$counts
    ): void {
        $jobs = [];
        $listings = [];
        foreach ($messages as $message) {
            $delivery = (int)$message[QueueManagement::MESSAGE_QUEUE_RELATION_ID];
            $id = (string)$message[QueueManagement::MESSAGE_BODY];
            try {
                if (!ctype_digit($id) || $id === '0') {
                    throw new RuntimeException('Invalid listing ID in condition queue.');
                }
                if (!$enabled) {
                    $completed = $this->store->complete($stage, $delivery, $id, null, null);
                    ++$counts[$completed ? 'skipped' : 'stale'];
                } elseif (($listing = $this->store->listing($id, $stage)) === null) {
                    $this->queue->acknowledge($delivery);
                    ++$counts['stale'];
                } else {
                    $jobs[$delivery] = $this->prepare($stage, $listing);
                    $listings[$delivery] = $listing;
                    continue;
                }
                unset($pending[$delivery]);
            } catch (Throwable) {
                $this->recordFailure($stage, $delivery, $message, $failures, $counts);
            }
        }

        if ($jobs === []) {
            return;
        }
        try {
            $results = $this->ollama->assess($stage, array_filter(
                $jobs,
                static fn(array $job): bool => !isset($job['missing'])
            ));
        } catch (OllamaUnavailable $error) {
            $results = array_fill_keys(array_keys($jobs), $error);
        }
        foreach ($jobs as $delivery => $job) {
            try {
                $assessment = $job['missing'] ?? $results[$delivery];
                if ($assessment instanceof Throwable) {
                    throw $assessment;
                }
                $result = ['model' => $this->settings->model($stage), 'assessment' => $assessment];
                if ($stage === Stage::Visual) {
                    $result['assessment']['image_count'] = count($job['images']);
                    $result['urls'] = $job['urls'];
                }
                $listing = $listings[$delivery];
                $completed = $this->store->complete($stage, $delivery, (string)$listing['id'], $listing, $result);
                ++$counts[$completed ? 'assessed' : 'stale'];
                unset($pending[$delivery]);
            } catch (OllamaUnavailable $error) {
                if (++$counts['deferred'] === 1) {
                    $this->logger->warning('GhostFrog {stage} condition is waiting for Ollama. {reason}', [
                        'stage' => $stage->value, 'reason' => $error->getMessage(),
                    ]);
                }
            } catch (Throwable) {
                $this->recordFailure($stage, $delivery, $pending[$delivery], $failures, $counts);
            }
        }
    }

    private function recordFailure(Stage $stage, int $delivery, array $message, array &$failures, array &$counts): void
    {
        $failures[$delivery] = $message;
        ++$counts['failed'];
        $this->logger->warning('GhostFrog {stage} condition failed for queue delivery {id}.', [
            'stage' => $stage->value, 'id' => $delivery,
        ]);
    }

    private function prepare(Stage $stage, array $listing): array
    {
        $attrs = json_decode((string)$listing['raw_attrs'], true);
        $attrs = is_array($attrs) ? $attrs : [];
        $title = mb_substr(trim((string)$listing['title']), 0, 255);
        if ($stage === Stage::Text) {
            $captured = $this->textEvidence($attrs);
            $job = ['evidence' => ['title' => $title, 'captured_text' => $captured], 'images' => []];
            if ($title === '' && $captured === []) {
                $job['missing'] = Assessment::missing($stage, 'No listing text available.');
            }
            return $job;
        }

        $job = $this->photos->load($attrs) + ['evidence' => ['title' => $title]];
        if ($job['images'] === []) {
            $job['missing'] = Assessment::missing($stage, 'No usable eBay photos available.');
        }
        return $job;
    }

    private function textEvidence(array $attrs): array
    {
        $result = [];
        $remaining = 10_000;
        foreach ($attrs as $name => $value) {
            if (!preg_match('~\A(?:Browse (?:condition|description|shortDescription|subtitle|aspect )|'
                . 'Trading (?:ConditionDescription|ConditionDisplayName|Description)|'
                . '(?:Condition|Condition Description|Description|Product Condition|Grade|Product Grade|'
                . 'Network Lock|Lock Status|Operation|Defects|Features)\z)~i', (string)$name)
            ) {
                continue;
            }
            $values = array_filter((array)$value, static fn($item) => is_scalar($item));
            $plain = trim(implode(', ', $values));
            $plain = html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($plain !== '') {
                $plain = mb_substr($plain, 0, min($remaining, 4000));
                $result[(string)$name] = $plain;
                $remaining -= mb_strlen($plain);
            }
            if ($remaining === 0 || count($result) >= 40) {
                break;
            }
        }
        return $result;
    }
}
