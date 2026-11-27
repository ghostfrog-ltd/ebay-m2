<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\Extraction;

use GhostFrog\Ebay\Api\AuctionCandidatesInterface;
use GhostFrog\Ebay\Api\ListingTextInterface;
use GhostFrog\Ebay\Api\LocalLlmInterface;
use GhostFrog\EbayShadow\Model\Config\Settings;
use JsonException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Extracts structured facts from the listing text core captured, using core's local Ollama endpoint.
 *
 * Text only: core's photo condition check already sends images to the same vision-capable model, and its
 * grade and confidence are part of shadow's evidence, so photos are not sent a second time.
 */
class Extractor
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AuctionCandidatesInterface $candidates,
        private readonly ListingTextInterface $text,
        private readonly LocalLlmInterface $llm,
        private readonly Store $store,
        private readonly LoggerInterface $logger
    ) {
    }

    public function model(array $settings): string
    {
        return $settings['llm/model'] !== '' ? $settings['llm/model'] : $this->llm->textModel();
    }

    /** Extracts upcoming decision candidates. @return array{extracted: int, failed: int} */
    public function run(?int $limit = null, ?int $now = null): array
    {
        $settings = $this->settings->resolve();
        if (!$this->settings->isActive() || !$settings['llm/enabled']) {
            return ['extracted' => 0, 'failed' => 0];
        }
        $now ??= time();
        // Ahead of the decision window, so the fields exist when the decision is made.
        $until = $now + ($settings['general/decision_window_minutes'] + 120) * 60;
        $ids = array_map('intval', array_keys($this->candidates->endingBetween(gmdate('Y-m-d H:i:s', $now),
            gmdate('Y-m-d H:i:s', $until))));
        $model = $this->model($settings);
        $version = $settings['llm/prompt_version'] ?: 'v1';
        $todo = array_slice($this->store->needed($ids, $model, $version), 0, $limit ?? max(1, $settings['llm/batch']));

        $counts = ['extracted' => 0, 'failed' => 0];
        foreach ($todo as $id) {
            $ok = $this->extract($id, $model, $version, $settings['llm/timeout']);
            ++$counts[$ok ? 'extracted' : 'failed'];
        }
        return $counts;
    }

    public function extract(int $listingId, string $model, string $version, int $timeout): bool
    {
        $evidence = $this->text->evidence($listingId);
        if ($evidence === null) {
            return false;
        }
        $input = json_encode($evidence, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $hash = hash('sha256', $version . "\n" . $input);
        try {
            $fields = Schema::validate($this->call($model, $input, $timeout));
            $this->store->save($listingId, $model, $version, $hash, Store::OK, $fields, null);
            return true;
        } catch (RuntimeException | JsonException $error) {
            $unreachable = $error->getCode() === 503;
            $this->store->save($listingId, $model, $version, $hash, $unreachable ? Store::ERROR : Store::INVALID,
                null, $error->getMessage());
            if ($unreachable) {
                $this->logger->info('GhostFrog shadow extraction could not reach Ollama.', ['listing' => $listingId]);
            }
            return false;
        } catch (Throwable $error) {
            $this->store->save($listingId, $model, $version, $hash, Store::ERROR, null, $error->getMessage());
            return false;
        }
    }

    private function call(string $model, string $input, int $timeout): mixed
    {
        try {
            $endpoint = $this->llm->chatEndpoint();
        } catch (RuntimeException $error) {
            throw new RuntimeException($error->getMessage(), 503);
        }
        $payload = json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => Schema::PROMPT],
                ['role' => 'user', 'content' => $input],
            ],
            'format' => Schema::json(),
            'stream' => false,
            'think' => false,
            'keep_alive' => '2m',
            'options' => ['temperature' => 0, 'num_predict' => 600],
        ], JSON_THROW_ON_ERROR);
        $handle = curl_init($endpoint);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Expect:'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(5, $timeout),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOPROXY => '*',
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($body === false || $status !== 200) {
            throw new RuntimeException('Local Ollama returned ' . ($body === false ? curl_error($handle) : "HTTP $status"), 503);
        }
        $content = json_decode((string)$body, true, 512, JSON_THROW_ON_ERROR)['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new RuntimeException('Ollama returned no content.');
        }
        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
