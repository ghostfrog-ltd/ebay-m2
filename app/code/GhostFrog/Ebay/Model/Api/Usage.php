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
    public const RECONCILE_SECONDS = 900;
    private const REQUEST_SETTLE_SECONDS = 60;

    public const ATTRIBUTES = 'ebay_attributes_v1';
    public const CATEGORY_SEARCH = 'ebay_base_fetch_category_items_v1';
    public const SELLER_SEARCH = 'ebay_base_seller_items_v1';
    public const ITEM_REFRESH = 'ebay_base_fetch_items_by_id_v1';
    public const TOKEN = 'ebay_auth_v1';
    public const RATE_LIMITS = 'ebay_developer_analytics_v1';

    private const SERVICES = [
        self::ATTRIBUTES => self::TRADING,
        'ebay_ended_v1' => self::TRADING,
        self::CATEGORY_SEARCH => self::BROWSE,
        self::SELLER_SEARCH => self::BROWSE,
        self::ITEM_REFRESH => self::BROWSE,
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

    public function requireAvailable(string $bucket, bool $itemRefresh = false): void
    {
        $this->check($bucket, $this->quota($bucket), $itemRefresh);
    }

    public function quota(string $bucket): array
    {
        return $this->state($this->resource->getConnection(), $bucket, time());
    }

    public function send(string $service, callable $request): void
    {
        $reservation = $this->reserve($service);
        $answered = false;
        try {
            $request();
            $answered = true;
        } finally {
            if ($reservation !== null) {
                $this->settle(self::SERVICES[$service], $reservation, $answered);
            }
        }
    }

    public function reserve(string $service): ?string
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
            $state = $this->state($db, $bucket, $now);
            $this->check($bucket, $state, $service === self::ITEM_REFRESH);
            $reservation = null;
            if ($this->usesLiveQuota($bucket)) {
                $snapshot = $this->snapshot($db);
                --$snapshot['rates'][$bucket]['remaining'];
                $reservation = bin2hex(random_bytes(12));
                $snapshot['sequence'][$bucket] = ($snapshot['sequence'][$bucket] ?? 0) + 1;
                $pending = $snapshot['pending'][$bucket] ?? [];
                $snapshot['pending'][$bucket] = array_filter($pending, static fn (int $until): bool => $until > $now);
                $snapshot['pending'][$bucket][$reservation] = $state['reset_at'] + self::REQUEST_SETTLE_SECONDS;
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
            return $reservation;
        } catch (Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    public function lookupIsDue(?string $bucket = null, bool $itemRefresh = false): bool
    {
        $db = $this->resource->getConnection();
        $now = time();
        $snapshot = $this->snapshot($db);
        if ($this->lookupBlockedUntil($db, $snapshot) > $now) {
            return false;
        }
        if ($this->refreshAt($snapshot) <= $now) {
            return true;
        }
        if ($this->reconcileAt($snapshot) > $now) {
            return false;
        }
        foreach ($bucket === null ? [self::BROWSE, self::TRADING] : [$bucket] as $candidate) {
            $rate = $snapshot['rates'][$candidate] ?? null;
            if ($rate === null || $this->blockedUntil($db, $candidate) > $now) {
                continue;
            }
            $floor = $itemRefresh && $candidate === self::BROWSE ? self::discoveryReserve($rate['limit']) : 0;
            if ($rate['remaining'] <= $floor && $rate['reported_remaining'] > $floor) {
                return true;
            }
        }
        return false;
    }

    public function beginLookup(): array
    {
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $this->lockBucket($db, self::ANALYTICS);
            $snapshot = $this->snapshot($db) ?? ['app' => $this->appKey()];
            $now = time();
            $snapshot['lookup_id'] = bin2hex(random_bytes(12));
            $snapshot['lookup_started_at'] = $now;
            $outstanding = [];
            foreach ([self::BROWSE, self::TRADING] as $bucket) {
                $pending = $snapshot['pending'][$bucket] ?? [];
                $uncertain = $snapshot['uncertain'][$bucket] ?? [];
                $snapshot['pending'][$bucket] = array_filter($pending, static fn (int $until): bool => $until > $now);
                $snapshot['uncertain'][$bucket] = array_filter(
                    $uncertain,
                    static fn (int $until): bool => $until > $now,
                    ARRAY_FILTER_USE_KEY
                );
                $outstanding[$bucket] = count($snapshot['pending'][$bucket]) + array_sum($snapshot['uncertain'][$bucket]);
            }
            $this->writeSnapshot($db, $snapshot);
            $db->commit();
            return [
                'id' => $snapshot['lookup_id'],
                'started_at' => $now,
                'sequence' => $snapshot['sequence'] ?? [],
                'rates' => $snapshot['rates'] ?? [],
                'outstanding' => $outstanding,
            ];
        } catch (Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    public function saveDailyRates(array $rates, int $checkedAt, array $lookup): array
    {
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $this->lockBucket($db, self::ANALYTICS);
            $snapshot = $this->snapshot($db);
            if (($snapshot['lookup_id'] ?? null) !== $lookup['id'] || $checkedAt < $lookup['started_at']) {
                $db->commit();
                return [];
            }
            unset($snapshot['lookup_id']);
            $accepted = [];
            foreach ($rates as $bucket => $rate) {
                $old = $snapshot['rates'][$bucket] ?? null;
                if ($rate['reset_at'] <= $checkedAt || ($old !== null
                    && ($rate['reset_at'] < $old['reset_at'] || ($rate['reset_at'] !== $old['reset_at'] && $old['reset_at'] > $checkedAt)))
                ) {
                    continue;
                }
                $rate = $this->reconcile($bucket, $rate, $old, $snapshot, $lookup, $checkedAt);
                $snapshot['rates'][$bucket] = $accepted[$bucket] = $rate;
            }
            $currentRates = array_filter($snapshot['rates'] ?? [], static fn (array $rate): bool => $rate['reset_at'] > $checkedAt);
            $refreshAt = min(array_column($currentRates, 'reset_at') ?: [PHP_INT_MAX]);
            if (!isset($accepted[self::TRADING], $accepted[self::BROWSE])) {
                $refreshAt = min($refreshAt, $checkedAt + self::LOOKUP_RETRY_SECONDS);
            }
            unset($snapshot['lookup_retry_at']);
            $snapshot['checked_at'] = $checkedAt;
            $snapshot['refresh_at'] = $refreshAt;
            $this->writeSnapshot($db, $snapshot);
            $db->commit();
            return $accepted;
        } catch (Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    private function reconcile(
        string $bucket,
        array $rate,
        ?array $old,
        array $snapshot,
        array $lookup,
        int $checkedAt
    ): array {
        $sameWindow = ($old['reset_at'] ?? null) === $rate['reset_at'];
        $sinceStart = ($snapshot['sequence'][$bucket] ?? 0) - ($lookup['sequence'][$bucket] ?? 0);
        if ($sameWindow) {
            // Also cover reservations by a worker still running the previous release.
            $sinceStart = max($sinceStart, ($lookup['rates'][$bucket]['remaining'] ?? 0) - $old['remaining']);
        }
        // A call outstanding when the lookup started may already be counted by eBay.
        // Deduct it anyway because the response has no per-call acknowledgement.
        $unconfirmed = $lookup['outstanding'][$bucket] + $sinceStart;
        $remaining = max(0, $rate['reported_remaining'] - $unconfirmed);
        $used = $rate['limit'] - $rate['reported_remaining'];
        if ($sameWindow) {
            $previousUsed = $old['provider_used_high_water'] ?? ($old['limit'] - $old['reported_remaining']);
            if ($used <= $previousUsed) {
                // Cached or regressing provider counts cannot restore spent credits.
                $remaining = min($old['remaining'], $remaining);
            }
            $used = max($used, $previousUsed);
        }
        $rate['remaining'] = $remaining;
        $rate['provider_used_high_water'] = $used;
        $rate['checked_at'] = $checkedAt;
        return $rate;
    }

    private function settle(string $bucket, string $reservation, bool $answered): void
    {
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $this->lockBucket($db, self::ANALYTICS);
            $snapshot = $this->snapshot($db);
            $until = $snapshot['pending'][$bucket][$reservation] ?? null;
            if ($until !== null) {
                unset($snapshot['pending'][$bucket][$reservation]);
                if (!$answered) {
                    $snapshot['uncertain'][$bucket][$until] = ($snapshot['uncertain'][$bucket][$until] ?? 0) + 1;
                }
                $this->writeSnapshot($db, $snapshot);
            }
            $db->commit();
        } catch (Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    public function postponeLookup(int $until): void
    {
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $this->lockBucket($db, self::ANALYTICS);
            $snapshot = $this->snapshot($db) ?? ['app' => $this->appKey()];
            $snapshot['lookup_retry_at'] = max($until, $snapshot['lookup_retry_at'] ?? 0);
            $this->writeSnapshot($db, $snapshot);
            $db->commit();
        } catch (Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
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
        return new QuotaUnavailable(
            $bucket,
            max($until, $this->blockedUntil($this->resource->getConnection(), $bucket)),
            'provider_throttle'
        );
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
        $reason = $retryAt ? 'provider_throttle' : null;
        $limit = null;
        $remaining = null;
        $resetAt = null;
        $checkedAt = null;
        $reportedRemaining = null;
        $nextLookup = null;

        if ($this->usesLiveQuota($bucket)) {
            $snapshot = $this->snapshot($db);
            $rate = $snapshot['rates'][$bucket] ?? null;
            if ($rate !== null && $rate['reset_at'] > $now) {
                $limit = $rate['limit'];
                $remaining = $rate['remaining'];
                $reportedRemaining = $rate['reported_remaining'];
                $resetAt = $rate['reset_at'];
                $checkedAt = $rate['checked_at'] ?? $snapshot['checked_at'];
                $nextLookup = min($resetAt, max(
                    $now + 1,
                    $this->reconcileAt($snapshot),
                    $this->lookupBlockedUntil($db, $snapshot)
                ));
                if ($remaining === 0 && $reason === null) {
                    $reason = $reportedRemaining === 0 ? 'provider_exhausted' : 'local_exhausted';
                    $retryAt = $reportedRemaining === 0 ? $resetAt : $nextLookup;
                }
            } else {
                $lookupBlocked = $this->lookupBlockedUntil($db, $snapshot);
                $retryAt = max($retryAt, $lookupBlocked, $this->refreshAt($snapshot), $now + 1);
                $reason ??= $lookupBlocked > $now ? 'analytics_retry' : 'awaiting_provider_quota';
            }
        } elseif ($bucket === self::OAUTH) {
            $limit = max(0, (int)($this->config->getValue('ghostfrog_ebay/ebay_api/oauth_daily_limit') ?? 1000));
            $remaining = max(0, $limit - $used);
            if ($remaining === 0 && $reason === null) {
                $retryAt = max($retryAt, self::nextUtcDay($now));
                $reason = 'local_exhausted';
            }
        } elseif ($bucket === self::ANALYTICS && $reason === null) {
            $lookupRetry = $this->snapshot($db)['lookup_retry_at'] ?? 0;
            if ($lookupRetry > $now) {
                $retryAt = $lookupRetry;
                $reason = 'analytics_retry';
            }
        }

        $reserve = $bucket === self::BROWSE && $limit !== null ? self::discoveryReserve($limit) : null;
        $refreshReason = $bucket === self::BROWSE ? $reason : null;
        $refreshRetry = $bucket === self::BROWSE ? $retryAt : 0;
        if ($reserve !== null && $remaining <= $reserve && $reason === null) {
            $refreshReason = 'discovery_reserve';
            $refreshRetry = $reportedRemaining <= $reserve ? $resetAt : $nextLookup;
        }

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => $retryAt ? 0 : $remaining,
            'reported_remaining' => $reportedRemaining,
            'reset_at' => $resetAt,
            'checked_at' => $checkedAt,
            'retry_at' => $retryAt,
            'pause_reason' => $reason,
            'discovery_reserve' => $reserve,
            'refresh_remaining' => $reserve === null ? null : ($refreshReason === null ? $remaining - $reserve : 0),
            'refresh_retry_at' => $refreshRetry,
            'refresh_pause_reason' => $refreshReason,
            'services' => $services,
        ];
    }

    private function check(string $bucket, array $state, bool $itemRefresh): void
    {
        $retryAt = $itemRefresh ? $state['refresh_retry_at'] : $state['retry_at'];
        if ($retryAt > 0) {
            $reason = $itemRefresh ? $state['refresh_pause_reason'] : $state['pause_reason'];
            throw new QuotaUnavailable($bucket, $retryAt, $reason);
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

    private function reconcileAt(?array $snapshot): int
    {
        return ($snapshot['lookup_started_at'] ?? $snapshot['checked_at'] ?? 0) + self::RECONCILE_SECONDS;
    }

    private static function discoveryReserve(int $limit): int
    {
        return intdiv($limit, 5) + (int)($limit % 5 !== 0);
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

    private function lookupBlockedUntil(AdapterInterface $db, ?array $snapshot): int
    {
        return max($this->blockedUntil($db, self::ANALYTICS), $snapshot['lookup_retry_at'] ?? 0);
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
