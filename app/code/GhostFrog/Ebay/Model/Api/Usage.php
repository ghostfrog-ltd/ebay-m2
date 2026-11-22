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

    public const ATTRIBUTES = 'ebay_attributes_v1';
    public const CATEGORY_SEARCH = 'ebay_base_fetch_category_items_v1';
    public const SELLER_SEARCH = 'ebay_base_seller_items_v1';
    public const TOKEN = 'ebay_auth_v1';

    private const DEFAULT_LIMITS = [self::TRADING => 5000, self::BROWSE => 5000, self::OAUTH => 1000];
    private const SERVICES = [
        self::ATTRIBUTES => self::TRADING,
        'ebay_ended_v1' => self::TRADING,
        self::CATEGORY_SEARCH => self::BROWSE,
        self::SELLER_SEARCH => self::BROWSE,
        'ebay_base_fetch_items_by_id_v1' => self::BROWSE,
        self::TOKEN => self::OAUTH,
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ScopeConfigInterface $config
    ) {
    }

    public function remaining(string $bucket): int
    {
        return $this->state($this->resource->getConnection(), $bucket, time())['remaining'];
    }

    public function requireAvailable(string $bucket): void
    {
        $this->check($bucket, $this->state($this->resource->getConnection(), $bucket, time()));
    }

    public function reserve(string $service): void
    {
        $bucket = self::SERVICES[$service];
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            // Lock this bucket until its usage has been checked and incremented.
            $db->insertOnDuplicate($this->quotaTable(), ['bucket' => $bucket], ['bucket']);
            $now = time();
            $this->check($bucket, $this->state($db, $bucket, $now));
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

    public function recordThrottle(string $bucket, array $headers): QuotaUnavailable
    {
        $now = time();
        $header = array_change_key_case($headers)['retry-after'] ?? '';
        $until = self::retryAt(trim((string)$header), $now);
        $db = $this->resource->getConnection();
        $db->insertOnDuplicate(
            $this->quotaTable(),
            ['bucket' => $bucket, 'blocked_until' => $until],
            ['blocked_until' => new Expression('GREATEST(blocked_until, VALUES(blocked_until))')]
        );
        $state = $this->state($db, $bucket, $now);
        return new QuotaUnavailable($bucket, $state['retry_at']);
    }

    public function summary(): array
    {
        $db = $this->resource->getConnection();
        $now = time();
        $buckets = [];
        foreach (array_keys(self::DEFAULT_LIMITS) as $bucket) {
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
        $limit = max(0, (int)($this->config->getValue('ghostfrog_ebay/ebay_api/' . $bucket . '_daily_limit')
            ?? self::DEFAULT_LIMITS[$bucket]));
        $used = array_sum($services);
        $blockedUntil = (int)$db->fetchOne($db->select()
            ->from($this->quotaTable(), 'blocked_until')->where('bucket = ?', $bucket));
        $retryAt = max(
            $blockedUntil > $now ? $blockedUntil : 0,
            $used >= $limit ? self::nextUtcDay($now) : 0
        );

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => $retryAt === 0 ? $limit - $used : 0,
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

        return $timestamp > $now ? $timestamp : self::nextUtcDay($now);
    }

    private static function nextUtcDay(int $now): int
    {
        return $now - ($now % 86400) + 86400;
    }
}
