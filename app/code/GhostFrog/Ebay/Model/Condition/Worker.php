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
                $messages = $this->queue->claim(1, $stage->queue());
                if ($messages !== []) {
                    $this->process($stage, reset($messages));
                }
            }
        } finally {
            $this->lock->unlock(self::LOCK);
        }
    }

    private function process(Stage $stage, array $message): void
    {
        $delivery = (int)$message[QueueManagement::MESSAGE_QUEUE_RELATION_ID];
        $id = (string)$message[QueueManagement::MESSAGE_BODY];
        try {
            if (!ctype_digit($id) || $id === '0') {
                throw new RuntimeException('Invalid listing ID in condition queue.');
            }
            $listing = $this->store->listing($id, $stage);
            if ($listing === null) {
                $this->queue->acknowledge($delivery);
                return;
            }
            $assessment = $this->settings->enabled($stage) ? $this->assess($stage, $listing) : null;
            $this->store->complete($stage, $delivery, $id, $listing, $assessment);
        } catch (OllamaUnavailable $exception) {
            $this->queue->retry([$delivery]);
            $this->logger->warning('GhostFrog {stage} condition is waiting for Ollama. {reason}', [
                'stage' => $stage->value,
                'reason' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            $this->queue->failOrRetry($message);
            $this->logger->warning('GhostFrog {stage} condition failed for queue delivery {id}.', [
                'stage' => $stage->value, 'id' => $delivery,
            ]);
        }
    }

    private function assess(Stage $stage, array $listing): array
    {
        $attrs = json_decode((string)$listing['raw_attrs'], true);
        $attrs = is_array($attrs) ? $attrs : [];
        $title = mb_substr(trim((string)$listing['title']), 0, 255);
        $model = $this->settings->model($stage);
        if ($stage === Stage::Text) {
            $captured = $this->textEvidence($attrs);
            $result = $title === '' && $captured === []
                ? Assessment::missing($stage, 'No listing text available.')
                : $this->ollama->assess($stage, ['title' => $title, 'captured_text' => $captured]);
            return ['model' => $model, 'assessment' => $result];
        }

        $photos = $this->photos->load($attrs);
        $result = $photos['images'] === []
            ? Assessment::missing($stage, 'No usable eBay photos available.')
            : $this->ollama->assess($stage, ['title' => $title], $photos['images']);
        $result['image_count'] = count($photos['images']);
        return ['model' => $model, 'assessment' => $result, 'urls' => $photos['urls']];
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
