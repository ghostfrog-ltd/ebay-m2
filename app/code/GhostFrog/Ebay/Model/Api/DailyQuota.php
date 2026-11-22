<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Api;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class DailyQuota
{
    private const LOCK = 'ghostfrog_ebay_daily_quota';
    private const URL = 'https://api.ebay.com/developer/analytics/v1_beta/rate_limit/';

    public function __construct(
        private readonly Usage $usage,
        private readonly ApplicationToken $token,
        private readonly ScopeConfigInterface $config,
        private readonly CurlFactory $curlFactory,
        private readonly LockManagerInterface $lock,
        private readonly LoggerInterface $logger
    ) {
    }

    public function refreshIfDue(): void
    {
        if (!$this->usage->dailyLookupIsDue()
            || !$this->config->isSetFlag('ghostfrog_ebay/ebay_api/gf_allow_ebay_api')
            || !$this->lock->lock(self::LOCK, 0)
        ) {
            return;
        }
        try {
            if ($this->usage->dailyLookupIsDue()) {
                $this->lookup();
            }
        } catch (QuotaUnavailable $exception) {
            if ($exception->bucket !== Usage::ANALYTICS) {
                $this->usage->postponeLookup($exception->retryAt);
            }
        } catch (Throwable $exception) {
            $this->usage->postponeLookup(time() + Usage::LOOKUP_RETRY_SECONDS);
            // HTTP and configuration exceptions can include credentials.
            $this->logger->warning('GhostFrog could not verify eBay daily quota ({type}).', [
                'type' => get_class($exception),
            ]);
        } finally {
            $this->lock->unlock(self::LOCK);
        }
    }

    private function lookup(): void
    {
        $this->usage->requireAvailable(Usage::ANALYTICS);
        $client = $this->curlFactory->create();
        $client->setTimeout(10);
        $client->setHeaders([
            'Authorization' => 'Bearer ' . $this->token->get(),
            'Accept' => 'application/json',
        ]);
        $this->usage->reserve(Usage::RATE_LIMITS);
        $client->get(self::URL);
        $status = (int)$client->getStatus();
        if ($status === 429) {
            throw $this->usage->recordThrottle(Usage::ANALYTICS, $client->getHeaders());
        }
        if ($status !== 200) {
            throw new RuntimeException('eBay quota lookup failed.', $status);
        }

        $payload = json_decode($client->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $checkedAt = time();
        $rates = $this->dailyRates($payload['rateLimits'] ?? [], $checkedAt);
        if ($rates === []) {
            throw new RuntimeException('eBay returned no current daily quota for Trading GetItem or Browse search.');
        }
        $this->usage->saveDailyRates($rates, $checkedAt);
    }

    private function dailyRates(array $apis, int $checkedAt): array
    {
        $rates = [];
        foreach ($apis as $api) {
            $name = strtolower($api['apiName'] ?? '');
            $context = strtolower($api['apiContext'] ?? '');
            foreach ($api['resources'] ?? [] as $resource) {
                $resourceName = strtolower($resource['name'] ?? '');
                $bucket = match (true) {
                    $name === 'tradingapi' && $resourceName === 'getitem' => Usage::TRADING,
                    $context === 'buy' && $name === 'browse' && $resourceName === 'buy.browse' => Usage::BROWSE,
                    default => null,
                };
                if ($bucket === null) {
                    continue;
                }
                foreach ($resource['rates'] ?? [] as $rate) {
                    if (($rate['timeWindow'] ?? null) !== 86400) {
                        continue;
                    }
                    $limit = $rate['limit'] ?? null;
                    $remaining = $rate['remaining'] ?? null;
                    $reset = $rate['reset'] ?? null;
                    $resetAt = is_string($reset) ? strtotime($reset) : false;
                    if (is_int($limit) && is_int($remaining) && $remaining >= 0
                        && $limit >= $remaining && $resetAt > $checkedAt
                        && (!isset($rates[$bucket]) || $remaining < $rates[$bucket]['remaining'])
                    ) {
                        $rates[$bucket] = [
                            'limit' => $limit,
                            'remaining' => $remaining,
                            'reported_remaining' => $remaining,
                            'reset_at' => $resetAt,
                        ];
                    }
                }
            }
        }
        return $rates;
    }
}
