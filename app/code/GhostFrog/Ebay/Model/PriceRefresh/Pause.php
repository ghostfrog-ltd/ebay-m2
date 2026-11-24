<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\PriceRefresh;

use Magento\Framework\App\CacheInterface;

final class Pause
{
    private const KEY = 'ghostfrog_ebay_price_refresh_pause';
    private const AUTH_SECONDS = 3600;
    private const LEGACY = 'legacy_pause';
    public const AUTHENTICATION = 'authentication';

    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function state(): ?array
    {
        $value = $this->cache->load(self::KEY);
        if ($value === '1') {
            // Older releases stored the same marker for quota and authentication.
            // Once it is older than an authentication cooldown, quota state can take over.
            $modifiedAt = $this->cache->getFrontend()->test(self::KEY);
            $until = is_int($modifiedAt) && $modifiedAt > 0 ? $modifiedAt + self::AUTH_SECONDS : null;
            return $until === null || $until > time() ? ['reason' => self::LEGACY, 'retry_at' => $until] : null;
        }
        if (is_string($value) && str_contains($value, ':')) {
            [$reason, $until] = explode(':', $value, 2);
            if ((int)$until > time()) {
                return ['reason' => $reason, 'retry_at' => (int)$until];
            }
        }
        return null;
    }

    public function holdAuthentication(): array
    {
        $until = time() + self::AUTH_SECONDS;
        $this->cache->save(self::AUTHENTICATION . ':' . $until, self::KEY, [], self::AUTH_SECONDS);
        return ['reason' => self::AUTHENTICATION, 'retry_at' => $until];
    }
}
