<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Ebay;

use InvalidArgumentException;

/**
 * eBay's digital signatures for API calls (required for UK sellers on the Finances API): an HTTP Message
 * Signature (RFC 9421) over x-ebay-signature-key, the method, path and host, signed with an Ed25519 key that
 * eBay's Key Management API issued.
 */
final class Signer
{
    private const COMPONENTS = ['x-ebay-signature-key', '@method', '@path', '@authority'];

    /**
     * @param string $privateKey eBay's privateKey: base64 PKCS#8 DER (PEM armour allowed) or a raw 32-byte seed.
     * @return array<string, string> Headers to add to the request.
     */
    public static function headers(string $method, string $url, string $jwe, string $privateKey, ?int $created = null): array
    {
        $created ??= time();
        $parts = parse_url($url);
        $path = $parts['path'] ?? '/';
        $authority = strtolower($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $params = '(' . implode(' ', array_map(static fn($c) => '"' . $c . '"', self::COMPONENTS)) . ');created=' . $created;
        $base = self::base(strtoupper($method), $path, $authority, $jwe, $params);
        $signature = sodium_crypto_sign_detached($base, self::secretKey($privateKey));
        return [
            'x-ebay-signature-key' => $jwe,
            'Signature-Input' => 'sig1=' . $params,
            'Signature' => 'sig1=:' . base64_encode($signature) . ':',
        ];
    }

    public static function base(string $method, string $path, string $authority, string $jwe, string $params): string
    {
        return implode("\n", [
            '"x-ebay-signature-key": ' . $jwe,
            '"@method": ' . $method,
            '"@path": ' . $path,
            '"@authority": ' . $authority,
            '"@signature-params": ' . $params,
        ]);
    }

    /** The 64-byte libsodium secret key for eBay's private key. */
    public static function secretKey(string $privateKey): string
    {
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $privateKey), true);
        if ($der === false) {
            throw new InvalidArgumentException('The eBay signing key is not valid base64.');
        }
        // PKCS#8 for Ed25519 ends with the 32-byte seed (OCTET STRING inside an OCTET STRING).
        $seed = strlen($der) === 32 ? $der : substr($der, -32);
        if (strlen($seed) !== 32 || (strlen($der) !== 32 && !str_contains($der, "\x06\x03\x2b\x65\x70"))) {
            throw new InvalidArgumentException('The eBay signing key is not an Ed25519 key.');
        }
        return sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed));
    }
}
