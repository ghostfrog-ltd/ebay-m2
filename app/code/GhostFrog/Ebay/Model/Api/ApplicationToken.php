<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Api;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use RuntimeException;

final class ApplicationToken
{
    private const API = 'https://api.ebay.com';
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

    public function get(): string
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

        $payload = json_decode($client->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('eBay returned an invalid application token response.');
        }
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
}
