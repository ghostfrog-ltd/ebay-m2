<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Account;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Math\Random;

/**
 * One-time state values linking eBay's consent redirect back to the account being connected.
 * They expire after 15 minutes and can be used once.
 */
class ConsentState
{
    private const PREFIX = 'ghostfrog_ledger_consent_';
    private const LIFETIME = 900;

    public function __construct(private readonly CacheInterface $cache, private readonly Random $random)
    {
    }

    public function issue(int $accountId): string
    {
        $state = $this->random->getRandomString(40, Random::CHARS_LOWERS . Random::CHARS_DIGITS);
        $this->cache->save((string)$accountId, $this->key($state), [], self::LIFETIME);
        return $state;
    }

    /** The account the state was issued for, or null when it is unknown, expired or already used. */
    public function consume(string $state): ?int
    {
        if (!preg_match('/\A[a-z0-9]{40}\z/', $state)) {
            return null;
        }
        $key = $this->key($state);
        $accountId = $this->cache->load($key);
        $this->cache->remove($key);
        return is_string($accountId) && ctype_digit($accountId) ? (int)$accountId : null;
    }

    private function key(string $state): string
    {
        return self::PREFIX . hash('sha256', $state);
    }
}
