<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Ebay;

use GhostFrog\Ebay\Model\Api\ApplicationToken;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use RuntimeException;

/**
 * The signing key Ledger uses for eBay calls that need digital signatures. It is created once, when you press
 * Create Signing Key, through eBay's Key Management API with core's application token. eBay shows the private
 * key only at creation, so Ledger stores it encrypted.
 */
class SigningKeys
{
    private const TABLE = 'ghostfrog_ebayledger_signing_key';
    private const CREATE_URL = 'https://apiz.ebay.com/developer/key_management/v1/signing_key';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EncryptorInterface $encryptor,
        private readonly CurlFactory $curlFactory,
        private readonly ApplicationToken $applicationToken
    ) {
    }

    /** @return array{signing_key_id: string, jwe: string, expires_at: ?string, created_at: string}|null */
    public function current(): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->resource->getTableName(self::TABLE),
            ['signing_key_id', 'jwe', 'expires_at', 'created_at'])
            ->where('expires_at IS NULL OR expires_at > UTC_TIMESTAMP()')->order('created_at DESC')->limit(1));
        return $row ?: null;
    }

    /** @return array<string, string> Signature headers for a request, using the current key. */
    public function sign(string $method, string $url): array
    {
        $db = $this->resource->getConnection();
        $key = $this->current();
        if ($key === null) {
            throw new RuntimeException('eBay needs a signing key for fees and payouts. Press Create Signing Key on GhostFrog → Ledger → Accounts.');
        }
        $encrypted = $db->fetchOne($db->select()->from($this->resource->getTableName(self::TABLE), ['private_key'])
            ->where('signing_key_id = ?', $key['signing_key_id']));
        return Signer::headers($method, $url, $key['jwe'], $this->encryptor->decrypt((string)$encrypted));
    }

    public function create(): array
    {
        $client = $this->curlFactory->create();
        $client->setTimeout(20);
        $client->setHeaders([
            'Authorization' => 'Bearer ' . $this->applicationToken->get(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);
        $client->post(self::CREATE_URL, json_encode(['signingKeyCipher' => 'ED25519']));
        $status = (int)$client->getStatus();
        $payload = json_decode((string)$client->getBody(), true);
        if (!in_array($status, [200, 201], true) || !is_array($payload) || empty($payload['privateKey']) || empty($payload['jwe'])) {
            $message = is_array($payload) ? (string)($payload['errors'][0]['message'] ?? '') : '';
            throw new RuntimeException(trim("eBay Key Management returned HTTP $status. $message"));
        }
        Signer::secretKey((string)$payload['privateKey']);
        $expires = isset($payload['expirationTime']) ? (int)$payload['expirationTime'] : null;
        $this->resource->getConnection()->insert($this->resource->getTableName(self::TABLE), [
            'signing_key_id' => (string)$payload['signingKeyId'],
            'cipher' => (string)($payload['signingKeyCipher'] ?? 'ED25519'),
            'jwe' => (string)$payload['jwe'],
            'private_key' => $this->encryptor->encrypt((string)$payload['privateKey']),
            'expires_at' => $expires ? gmdate('Y-m-d H:i:s', $expires > 20000000000 ? intdiv($expires, 1000) : $expires) : null,
        ]);
        return $this->current() ?? [];
    }
}
