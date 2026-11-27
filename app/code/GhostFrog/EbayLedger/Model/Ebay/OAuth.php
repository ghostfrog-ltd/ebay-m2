<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Ebay;

use GhostFrog\EbayLedger\Model\Config\Settings;
use Magento\Framework\HTTP\Client\CurlFactory;
use RuntimeException;

/**
 * eBay's user-consent OAuth (authorization code grant) for the accounts Ledger reads.
 *
 * Exceptions never carry tokens, codes or the client secret.
 */
class OAuth
{
    private const AUTHORIZE = 'https://auth.ebay.com/oauth2/authorize';
    private const TOKEN = 'https://api.ebay.com/identity/v1/oauth2/token';

    public function __construct(
        private readonly Settings $settings,
        private readonly CurlFactory $curlFactory
    ) {
    }

    public function configured(): bool
    {
        return $this->settings->appId() !== '' && $this->settings->certId() !== '' && $this->settings->ruName() !== ''
            && $this->settings->scopes() !== [];
    }

    /** eBay's sign-in and consent page. prompt=login makes eBay ask who is signing in, for a second account. */
    public function authorizeUrl(string $state): string
    {
        $this->requireConfigured();
        return self::AUTHORIZE . '?' . http_build_query([
            'client_id' => $this->settings->appId(),
            'response_type' => 'code',
            'redirect_uri' => $this->settings->ruName(),
            'scope' => implode(' ', $this->settings->scopes()),
            'state' => $state,
            'prompt' => 'login',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{access_token: string, expires_in: int, refresh_token: string, refresh_token_expires_in: int}
     */
    public function exchange(string $code): array
    {
        $payload = $this->post([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->settings->ruName(),
        ]);
        if (!is_string($payload['refresh_token'] ?? null) || $payload['refresh_token'] === '') {
            throw new RuntimeException('eBay did not return a refresh token.');
        }
        return [
            'access_token' => $payload['access_token'],
            'expires_in' => (int)$payload['expires_in'],
            'refresh_token' => $payload['refresh_token'],
            'refresh_token_expires_in' => (int)($payload['refresh_token_expires_in'] ?? 0),
        ];
    }

    /**
     * @param string[] $scopes The scopes the account consented to; eBay rejects anything wider.
     * @return array{access_token: string, expires_in: int}
     */
    public function refresh(string $refreshToken, array $scopes): array
    {
        $payload = $this->post([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => implode(' ', $scopes),
        ]);
        return ['access_token' => $payload['access_token'], 'expires_in' => (int)$payload['expires_in']];
    }

    private function post(array $fields): array
    {
        $this->requireConfigured();
        $client = $this->curlFactory->create();
        $client->setTimeout(15);
        $client->setHeaders([
            'Authorization' => 'Basic ' . base64_encode($this->settings->appId() . ':' . $this->settings->certId()),
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ]);
        $client->post(self::TOKEN, $fields);
        $status = (int)$client->getStatus();
        $payload = json_decode((string)$client->getBody(), true);
        if ($status !== 200 || !is_array($payload)) {
            $error = is_array($payload) ? (string)($payload['error'] ?? '') : '';
            $description = is_array($payload) ? (string)($payload['error_description'] ?? '') : '';
            throw new RuntimeException(trim(sprintf('eBay OAuth returned HTTP %d %s %s', $status, $error,
                mb_substr($description, 0, 200))), $status);
        }
        if (!is_string($payload['access_token'] ?? null) || $payload['access_token'] === ''
            || (int)($payload['expires_in'] ?? 0) <= 0) {
            throw new RuntimeException('eBay returned an invalid user token.');
        }
        return $payload;
    }

    private function requireConfigured(): void
    {
        if (!$this->configured()) {
            throw new RuntimeException('Set the eBay Application ID and Certificate ID (eBay API) and the Ledger RuName and scopes in Stores → Configuration → GhostFrog.');
        }
    }
}
