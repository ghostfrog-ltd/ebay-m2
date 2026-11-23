<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Api;

use DateTimeImmutable;
use DateTimeZone;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use Throwable;

final class Usage
{
    public const TRADING = 'trading';
    public const BROWSE = 'browse';
    public const OAUTH = 'oauth';
    public const ANALYTICS = 'analytics';
    public const LOOKUP_RETRY_SECONDS = 300;

    public const ATTRIBUTES = 'ebay_attributes_v1';
    public const CATEGORY_SEARCH = 'ebay_base_fetch_category_items_v1';
    public const SELLER_SEARCH = 'ebay_base_seller_items_v1';
    public const TOKEN = 'ebay_auth_v1';
    public const RATE_LIMITS = 'ebay_developer_analytics_v1';

    private const SERVICES = [
        self::ATTRIBUTES => self::TRADING,
        'ebay_ended_v1' => self::TRADING,
        self::CATEGORY_SEARCH => self::BROWSE,
        self::SELLER_SEARCH => self::BROWSE,
        'ebay_base_fetch_items_by_id_v1' => self::BROWSE,
        self::TOKEN => self::OAUTH,
        self::RATE_LIMITS => self::ANALYTICS,
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ScopeConfigInterface $config
    ) {
    }

    public function remaining(string $bucket): int
    {
        return $this->quota($bucket)['remaining'] ?? 0;
    }

    public function requireAvailable(string $bucket): void
    {
        $this->check($bucket, $this->quota($bucket));
    }

    public function quota(string $bucket): array
    {
        return $this->state($this->resource->getConnection(), $bucket, time());
    }

    public function reserve(string $service): void
    {
        $bucket = self::SERVICES[$service];
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            // Hold the snapshot and bucket rows until the reservation is committed.
            if ($this->usesLiveQuota($bucket)) {
                $this->lockBucket($db, self::ANALYTICS);
            }
            $this->lockBucket($db, $bucket);
            $now = time();
            $this->check($bucket, $this->state($db, $bucket, $now));
            if ($this->usesLiveQuota($bucket)) {
                $snapshot = $this->snapshot($db);
                --$snapshot['rates'][$bucket]['remaining'];
                $this->writeSnapshot($db, $snapshot);
            }
            $db->insertOnDuplicate(
                $this->resource->getTableName('ghostfrog_ebay_api_usage'),
                [
                    'service' => $service,
                    'call_count' => 1,
                    'date' => gmdate('Y-m-d', $now),
                    'created_at' => gmdate('Y-m-d H:i:s', $now),
                    'updated_at' => gmdate('Y-m-d H:i:s', $now),
                ],
                ['call_count' => new Expression('call_count + 1'), 'updated_at']
            );
            $db->commit();
        } catch (Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    public function dailyLookupIsDue(): bool
    {
        $db = $this->resource->getConnection();
        $now = time();
        return $this->refreshAt($this->snapshot($db)) <= $now
            && $this->blockedUntil($db, self::ANALYTICS) <= $now;
    }

    public function saveDailyRates(array $rates, int $checkedAt): void
    {
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $this->lockBucket($db, self::ANALYTICS);
            $previous = $this->snapshot($db);
            $refreshAt = min(array_column($rates, 'reset_at'));
            if (!isset($rates[self::TRADING], $rates[self::BROWSE])) {
                $refreshAt = min($refreshAt, $checkedAt + self::LOOKUP_RETRY_SECONDS);
            }
            foreach ($rates as $bucket => $rate) {
                $rates[$bucket]['checked_at'] = $checkedAt;
                $old = $previous['rates'][$bucket] ?? null;
                if ($old !== null && $old['reset_at'] === $rate['reset_at']) {
                    // A refresh for another API must not reissue credits already reserved here.
                    $rates[$bucket]['remaining'] = min($old['remaining'], $rate['remaining']);
                }
            }
            foreach ($previous['rates'] ?? [] as $bucket => $rate) {
                if (!isset($rates[$bucket]) && $rate['reset_at'] > $checkedAt) {
                    $rates[$bucket] = $rate + ['checked_at' => $previous['checked_at']];
                    $refreshAt = min($refreshAt, $rate['reset_at']);
                }
            }
            $this->writeSnapshot($db, [
                'app' => $this->appKey(),
                'checked_at' => $checkedAt,
                'refresh_at' => $refreshAt,
                'rates' => $rates,
            ]);
            $db->commit();
        } catch (Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    public function postponeLookup(int $until): void
    {
        $this->block(self::ANALYTICS, $until);
    }

    public function recordThrottle(string $bucket, array $headers): QuotaUnavailable
    {
        $now = time();
        $header = array_change_key_case($headers)['retry-after'] ?? '';
        $snapshot = $this->snapshot($this->resource->getConnection());
        $reset = $snapshot['rates'][$bucket]['reset_at'] ?? 0;
        $fallback = match (true) {
            $reset > $now => $reset,
            $bucket === self::OAUTH => self::nextUtcDay($now),
            default => $now + self::LOOKUP_RETRY_SECONDS,
        };
        $until = self::retryAt(trim((string)$header), $now) ?: $fallback;
        $this->block($bucket, $until);
        $state = $this->state($this->resource->getConnection(), $bucket, $now);
        return new QuotaUnavailable($bucket, $state['retry_at']);
    }

    public function summary(): array
    {
        $db = $this->resource->getConnection();
        $now = time();
        $buckets = [];
        foreach ([self::TRADING, self::BROWSE, self::OAUTH, self::ANALYTICS] as $bucket) {
            $buckets[$bucket] = $this->state($db, $bucket, $now);
        }
        return ['date' => gmdate('Y-m-d', $now), 'buckets' => $buckets];
    }

    private function state(AdapterInterface $db, string $bucket, int $now): array
    {
        $services = array_fill_keys(array_keys(self::SERVICES, $bucket, true), 0);
        $counts = $db->fetchPairs($db->select()->from(
            $this->resource->getTableName('ghostfrog_ebay_api_usage'),
            ['service', 'call_count']
        )->where('date = ?', gmdate('Y-m-d', $now))->where('service IN (?)', array_keys($services)));
        $services = array_replace($services, array_map('intval', $counts));
        $used = array_sum($services);
        $blockedUntil = $this->blockedUntil($db, $bucket);
        $retryAt = $blockedUntil > $now ? $blockedUntil : 0;
        $limit = null;
        $remaining = null;
        $resetAt = null;
        $checkedAt = null;
        $reportedRemaining = null;

        if ($this->usesLiveQuota($bucket)) {
            $snapshot = $this->snapshot($db);
            $rate = $snapshot['rates'][$bucket] ?? null;
            if ($rate !== null && $rate['reset_at'] > $now) {
                $limit = $rate['limit'];
                $remaining = $rate['remaining'];
                $reportedRemaining = $rate['reported_remaining'];
                $resetAt = $rate['reset_at'];
                $checkedAt = $rate['checked_at'] ?? $snapshot['checked_at'];
                if ($remaining === 0) {
                    $retryAt = max($retryAt, $resetAt);
                }
            } else {
                $lookupBlocked = $this->blockedUntil($db, self::ANALYTICS);
                $retryAt = max($retryAt, $lookupBlocked, $this->refreshAt($snapshot), $now + 1);
            }
        } elseif ($bucket === self::OAUTH) {
            $limit = max(0, (int)($this->config->getValue('ghostfrog_ebay/ebay_api/oauth_daily_limit') ?? 1000));
            $remaining = max(0, $limit - $used);
            if ($remaining === 0) {
                $retryAt = max($retryAt, self::nextUtcDay($now));
            }
        }

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => $retryAt ? 0 : $remaining,
            'reported_remaining' => $reportedRemaining,
            'reset_at' => $resetAt,
            'checked_at' => $checkedAt,
            'retry_at' => $retryAt,
            'services' => $services,
        ];
    }

    private function check(string $bucket, array $state): void
    {
        if ($state['retry_at'] > 0) {
            throw new QuotaUnavailable($bucket, $state['retry_at']);
        }
    }

    private function usesLiveQuota(string $bucket): bool
    {
        return in_array($bucket, [self::TRADING, self::BROWSE], true);
    }

    private function appKey(): string
    {
        return hash('sha256', trim((string)$this->config->getValue('ghostfrog_ebay/ebay_api/ebay_app_id')));
    }

    private function snapshot(AdapterInterface $db): ?array
    {
        $value = $db->fetchOne($db->select()
            ->from($this->quotaTable(), 'daily_snapshot')->where('bucket = ?', self::ANALYTICS));
        $snapshot = json_decode((string)$value, true);
        return ($snapshot['app'] ?? null) === $this->appKey() ? $snapshot : null;
    }

    private function refreshAt(?array $snapshot): int
    {
        $scheduled = $snapshot['refresh_at'] ?? 0;
        // Older snapshots scheduled partial responses for the next eBay reset.
        return isset($snapshot['rates'][self::TRADING], $snapshot['rates'][self::BROWSE])
            ? $scheduled
            : min($scheduled, ($snapshot['checked_at'] ?? 0) + self::LOOKUP_RETRY_SECONDS);
    }

    private function writeSnapshot(AdapterInterface $db, array $snapshot): void
    {
        $db->update(
            $this->quotaTable(),
            ['daily_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)],
            ['bucket = ?' => self::ANALYTICS]
        );
    }

    private function blockedUntil(AdapterInterface $db, string $bucket): int
    {
        return (int)$db->fetchOne($db->select()
            ->from($this->quotaTable(), 'blocked_until')->where('bucket = ?', $bucket));
    }

    private function block(string $bucket, int $until): void
    {
        $this->resource->getConnection()->insertOnDuplicate(
            $this->quotaTable(),
            ['bucket' => $bucket, 'blocked_until' => $until],
            ['blocked_until' => new Expression('GREATEST(blocked_until, VALUES(blocked_until))')]
        );
    }

    private function lockBucket(AdapterInterface $db, string $bucket): void
    {
        $db->insertOnDuplicate($this->quotaTable(), ['bucket' => $bucket], ['bucket']);
    }

    private function quotaTable(): string
    {
        return $this->resource->getTableName('ghostfrog_ebay_api_quota');
    }

    private static function retryAt(string $header, int $now): int
    {
        if (ctype_digit($header)) {
            $timestamp = $now + min((int)$header, PHP_INT_MAX - $now);
        } else {
            $date = DateTimeImmutable::createFromFormat(DATE_RFC7231, $header, new DateTimeZone('UTC'));
            $timestamp = $date && $date->format(DATE_RFC7231) === $header ? $date->getTimestamp() : 0;
        }

        return $timestamp > $now ? $timestamp : 0;
    }

    private static function nextUtcDay(int $now): int
    {
        return $now - ($now % 86400) + 86400;
    }
}
