<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Collection;

use DateTimeImmutable;
use GhostFrog\Ebay\Model\Api\Usage;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use RuntimeException;

final class BrowseClient
{
    private const API = 'https://api.ebay.com';
    private const PAGE_SIZE = 50;
    private ?string $token = null;
    private int $expiresAt = 0;

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly ScopeConfigInterface $config,
        private readonly EncryptorInterface $encryptor,
        private readonly CacheInterface $cache,
        private readonly Usage $usage
    ) {
    }

    /**
     * Yield summaries with their requested sale type. Collection commits after the
     * entire source succeeds, so a failed query cannot count as a completed run.
     */
    public function search(array $definition, DateTimeImmutable $now): iterable
    {
        $saleTypes = $definition['sale_types'] ?? ['bin', 'auction'];
        foreach ($saleTypes as $saleType) {
            $params = [
                'limit' => self::PAGE_SIZE,
                'sort' => $saleType === 'bin' ? 'newlyListed' : 'endingSoon',
            ];
            $option = $saleType === 'bin' ? 'FIXED_PRICE' : 'AUCTION';
            $buyingFilter = 'buyingOptions:{' . $option . '}';
            if (isset($definition['seller'])) {
                yield from $this->sellerSearch($definition, $now, $saleType, $params, $buyingFilter);
                continue;
            }

            foreach ($definition['categories'] as $category) {
                $queries = $definition['category_queries'][$category] ?? $definition['queries'];
                foreach ($queries as $query) {
                    $payload = $this->get($params + [
                        'q' => $query,
                        'category_ids' => $category,
                        'filter' => $buyingFilter,
                    ], Usage::CATEGORY_SEARCH);
                    foreach ($this->summaries($payload) as $item) {
                        yield [$saleType, $item];
                    }
                }
            }
        }
    }

    private function sellerSearch(
        array $definition,
        DateTimeImmutable $now,
        string $saleType,
        array $params,
        string $buyingFilter
    ): iterable {
        $seller = $definition['seller'];
        foreach ([60, 14, 7] as $days) {
            $end = $now->modify("+$days days");
            $datePattern = 'Y-m-d\\TH:i:s\\Z';
            $filter = implode(',', [
                'sellers:{' . $seller . '}',
                'itemEndDate:[' . $now->format($datePattern) . '..' . $end->format($datePattern) . ']',
                'itemLocationCountry:GB',
                $buyingFilter,
            ]);
            $seen = [];
            for ($page = 0; $page < $definition['page_limit']; $page++) {
                $payload = $this->get($params + [
                    'q' => $definition['queries'][0],
                    'filter' => $filter,
                    'offset' => self::PAGE_SIZE * $page,
                ], Usage::SELLER_SEARCH, $page === 0);
                if ($payload === null) {
                    continue 2;
                }

                $before = count($seen);
                foreach ($this->summaries($payload) as $item) {
                    $id = $item['itemId'] ?? '';
                    if ($id === '' || isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    if (strcasecmp(trim((string)($item['seller']['username'] ?? '')), $seller) === 0) {
                        yield [$saleType, $item];
                    }
                    if (count($seen) >= $definition['listing_limit']) {
                        return;
                    }
                }
                if (count($seen) === $before || empty($payload['next'])) {
                    break;
                }
            }
            if ($seen !== []) {
                return;
            }
            $acceptedWindow = true;
        }

        if (!isset($acceptedWindow)) {
            throw new RuntimeException('eBay rejected all seller search windows.');
        }
    }

    private function get(array $params, string $service, bool $allowNarrowerWindow = false): ?array
    {
        $token = $this->token();
        $client = $this->curlFactory->create();
        $client->setTimeout(10);
        $client->setHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
            'X-EBAY-C-MARKETPLACE-ID' => 'EBAY_GB',
        ]);
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $this->usage->reserve($service);
        $client->get(self::API . '/buy/browse/v1/item_summary/search?' . $query);
        $status = (int)$client->getStatus();
        if ($status === 429) {
            throw $this->usage->recordThrottle(Usage::BROWSE, $client->getHeaders());
        }
        if ($allowNarrowerWindow && $status === 400 && preg_match('/too (?:large|many)/i', $client->getBody())) {
            return null;
        }
        if ($status !== 200) {
            throw new RuntimeException(sprintf('eBay Browse returned HTTP %d.', $status));
        }

        return $this->decode($client->getBody());
    }

    private function token(): string
    {
        if ($this->token !== null && $this->expiresAt > time() + 60) {
            return $this->token;
        }

        $appId = trim((string)$this->config->getValue('ghostfrog_ebay/ebay_api/ebay_app_id'));
        // Magento's config backend returns the decrypted Certificate ID.
        $clientSecret = trim((string)$this->config->getValue('ghostfrog_ebay/ebay_api/ebay_cert_id'));
        if ($appId === '' || $clientSecret === '') {
            throw new RuntimeException('Configure the eBay Application ID and Certificate ID in Magento.');
        }

        $cacheKey = 'ghostfrog_ebay_browse_token_' . hash('sha256', $appId);
        $cached = $this->cache->load($cacheKey);
        if (is_string($cached) && $cached !== '') {
            $token = $this->encryptor->decrypt($cached);
            if ($token !== '') {
                $this->token = $token;
                $this->expiresAt = time() + 65;
                return $token;
            }
        }

        $client = $this->curlFactory->create();
        $client->setTimeout(10);
        $client->setHeaders([
            'Authorization' => 'Basic ' . base64_encode($appId . ':' . $clientSecret),
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ]);
        // Mint a token only when there is Browse budget for the search.
        $this->usage->requireAvailable(Usage::BROWSE);
        $this->usage->reserve(Usage::TOKEN);
        $client->post(self::API . '/identity/v1/oauth2/token', [
            'grant_type' => 'client_credentials',
            'scope' => self::API . '/oauth/api_scope',
        ]);
        $status = (int)$client->getStatus();
        if ($status === 429) {
            throw $this->usage->recordThrottle(Usage::OAUTH, $client->getHeaders());
        }
        if ($status !== 200) {
            throw new RuntimeException(sprintf('eBay OAuth returned HTTP %d.', $status));
        }

        $payload = $this->decode($client->getBody());
        $token = $payload['access_token'] ?? null;
        $lifetime = (int)($payload['expires_in'] ?? 0);
        if (!is_string($token) || $token === '' || $lifetime <= 0) {
            throw new RuntimeException('eBay returned an invalid application token.');
        }
        $this->token = $token;
        $this->expiresAt = time() + $lifetime;
        if ($lifetime > 60) {
            $this->cache->save($this->encryptor->encrypt($token), $cacheKey, [], $lifetime - 60);
        }
        return $token;
    }

    private function decode(string $body): array
    {
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('eBay returned an invalid response.');
        }
        return $payload;
    }

    private function summaries(array $payload): array
    {
        $items = $payload['itemSummaries'] ?? $payload['item_summary'] ?? null;
        if ($items === null && ($payload['total'] ?? null) === 0) {
            return [];
        }
        if (!is_array($items) || !array_is_list($items)) {
            throw new RuntimeException('eBay response is missing its listing summaries.');
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('eBay returned an invalid listing summary.');
            }
        }
        return $items;
    }
}
