<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Api;

/**
 * Turns the daily quota summary into one admin grid row per eBay API.
 */
final class UsageRows
{
    private const APIS = [
        Usage::TRADING => 'Trading API (GetItem)',
        Usage::BROWSE => 'Browse API',
        Usage::OAUTH => 'OAuth (application token)',
        Usage::ANALYTICS => 'Developer Analytics (rate limits)',
    ];

    private const SERVICES = [
        Usage::ATTRIBUTES => 'Item attributes',
        'ebay_ended_v1' => 'Ended item outcomes',
        Usage::CATEGORY_SEARCH => 'Category search',
        Usage::SELLER_SEARCH => 'Seller search',
        Usage::ITEM_REFRESH => 'Item price refresh',
        Usage::CANDIDATE_REFRESH => 'Auction prequalification',
        Usage::TOKEN => 'Application token',
        Usage::RATE_LIMITS => 'Quota lookup',
    ];

    private const REASONS = [
        'provider_throttle' => 'Paused: eBay throttled requests',
        'provider_exhausted' => 'Paused: eBay daily quota used up',
        'local_exhausted' => 'Paused: Magento daily allowance used up',
        'analytics_retry' => 'Paused: waiting to re-check quota',
        'awaiting_provider_quota' => 'Waiting for quota from eBay',
    ];

    public function __construct(private readonly Usage $usage)
    {
    }

    public function rows(): array
    {
        return self::fromSummary($this->usage->summary());
    }

    public static function fromSummary(array $summary): array
    {
        $rows = [];
        foreach ($summary['buckets'] as $bucket => $state) {
            $limit = $state['limit'];
            $remaining = $state['remaining'];
            $rows[] = [
                'api' => $bucket,
                'api_name' => self::APIS[$bucket] ?? $bucket,
                'date' => $summary['date'],
                'calls_today' => $state['used'],
                'daily_limit' => $limit,
                'remaining' => $limit === null ? null : $remaining,
                'used_percent' => $limit ? round(100 * ($limit - (int)$remaining) / $limit, 1) : null,
                'reported_remaining' => $state['reported_remaining'],
                'checked_at' => self::date($state['checked_at']),
                'reset_at' => self::date($state['reset_at']),
                'status' => $state['pause_reason'] === null
                    ? 'Available'
                    : (self::REASONS[$state['pause_reason']] ?? $state['pause_reason']),
                'retry_at' => self::date($state['retry_at']),
                'services' => self::services($state['services']),
            ];
        }
        return $rows;
    }

    private static function services(array $services): string
    {
        $parts = [];
        foreach ($services as $service => $count) {
            $parts[] = (self::SERVICES[$service] ?? $service) . ': ' . number_format($count);
        }
        return implode(', ', $parts);
    }

    private static function date(?int $timestamp): ?string
    {
        return $timestamp ? gmdate('Y-m-d H:i', $timestamp) . ' UTC' : null;
    }
}
