<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\PriceRefresh;

use Magento\Framework\App\Config\ScopeConfigInterface;

final class Settings
{
    private const PREFIX = 'ghostfrog_ebay/opportunity_monitoring/';
    public const AUCTION_ALERT_WINDOW = 300;

    public function __construct(private readonly ScopeConfigInterface $config)
    {
    }

    public function enabled(): bool
    {
        return $this->config->isSetFlag('ghostfrog_ebay/general/enabled')
            && $this->config->isSetFlag('ghostfrog_ebay/ebay_api/gf_allow_ebay_api');
    }

    /** Auction tiers, shortest remaining time first. */
    public function auctionIntervals(): array
    {
        return [
            600 => 60 * $this->number('auction_10_minutes', 1, 1440),
            3600 => 60 * $this->number('auction_60_minutes', 5, 1440),
            86400 => 60 * $this->number('auction_24_hours', 30, 1440),
        ];
    }

    public function binInterval(): int
    {
        return 60 * $this->number('bin_minutes', 5, 1440);
    }

    public function interval(string $saleType, ?int $remaining): ?int
    {
        $interval = $saleType === 'auction' ? null : $this->binInterval();
        if ($saleType !== 'bin' && $remaining !== null) {
            foreach ($this->auctionIntervals() as $window => $seconds) {
                if ($remaining <= $window) {
                    return min($interval ?? $seconds, $seconds);
                }
            }
        }
        return $interval;
    }

    public function refreshBatch(): int
    {
        return $this->number('refresh_batch', 10, 100);
    }

    public function notificationBatch(): int
    {
        return $this->number('notification_batch', 10, 100);
    }

    public function auctionAlertAge(): int
    {
        return $this->number('auction_alert_age', 60, self::AUCTION_ALERT_WINDOW);
    }

    public function slackDestination(): ?string
    {
        if (!$this->config->isSetFlag('ghostfrog_ebay/general/enabled')
            || !$this->config->isSetFlag(self::PREFIX . 'notifications_enabled')
        ) {
            return null;
        }
        $url = trim((string)$this->config->getValue('ghostfrog_ebay/slack/slack_webhook_url'));
        // Incoming webhook URLs contain a credential. Accept only Slack's webhook endpoint.
        return preg_match('~\Ahttps://hooks\.slack(?:-gov)?\.com/services/[A-Za-z0-9_-]+/[A-Za-z0-9_-]+/[A-Za-z0-9_-]+\z~', $url)
            ? $url : null;
    }

    public function isFresh(array $listing, int $now, ?int $maxAge): bool
    {
        $checked = self::timestamp($listing['last_price_checked_at']);
        $observed = self::timestamp($listing['last_seen_at']);
        return $checked !== null && $checked === $observed && $checked <= $now
            && ($maxAge === null || $now - $checked < $maxAge);
    }

    public static function timestamp(?string $date): ?int
    {
        return $date === null ? null : (strtotime($date . ' UTC') ?: null);
    }

    private function number(string $field, int $default, int $max): int
    {
        $value = $this->config->getValue(self::PREFIX . $field);
        return ctype_digit((string)$value) ? max(1, min($max, (int)$value)) : $default;
    }
}
