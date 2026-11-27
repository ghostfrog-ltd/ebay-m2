<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Test\Unit\Model\Ebay;

use GhostFrog\EbayLedger\Model\Ebay\Signer;
use PHPUnit\Framework\TestCase;

class SignerTest extends TestCase
{
    /** A throwaway Ed25519 key as base64 PKCS#8 DER, the way eBay's Key Management API returns one. */
    private static function key(): array
    {
        $seed = random_bytes(32);
        $der = hex2bin('302e020100300506032b657004220420') . $seed;
        $public = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));
        return [base64_encode($der), $public];
    }

    public function testSignsTheComponentsEbayCovers(): void
    {
        [$private, $public] = self::key();
        $headers = Signer::headers('GET', 'https://apiz.ebay.com/sell/finances/v1/transaction?limit=5', 'jwe.a.b.c.d', $private, 1700000000);

        $this->assertSame('jwe.a.b.c.d', $headers['x-ebay-signature-key']);
        $params = '("x-ebay-signature-key" "@method" "@path" "@authority");created=1700000000';
        $this->assertSame('sig1=' . $params, $headers['Signature-Input']);
        $this->assertMatchesRegularExpression('/^sig1=:[A-Za-z0-9+\/]+=*:$/', $headers['Signature']);

        // The path leaves out the query string, as RFC 9421's @path does.
        $base = "\"x-ebay-signature-key\": jwe.a.b.c.d\n\"@method\": GET\n\"@path\": /sell/finances/v1/transaction\n"
            . "\"@authority\": apiz.ebay.com\n\"@signature-params\": " . $params;
        $signature = base64_decode(substr($headers['Signature'], 6, -1));
        $this->assertTrue(sodium_crypto_sign_verify_detached($signature, $base, $public));
    }

    public function testAcceptsPemArmour(): void
    {
        [$private, $public] = self::key();
        $pem = "-----BEGIN PRIVATE KEY-----\n" . chunk_split($private, 64, "\n") . "-----END PRIVATE KEY-----\n";
        $this->assertSame(sodium_crypto_sign_publickey_from_secretkey(Signer::secretKey($pem)), $public);
    }

    public function testRejectsKeysThatAreNotEd25519(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Signer::secretKey(base64_encode(str_repeat('x', 48)));
    }
}
