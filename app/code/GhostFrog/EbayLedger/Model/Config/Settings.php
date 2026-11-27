<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;

/** Ledger's settings under GhostFrog → eBay Configuration → Ledger, plus the core eBay keys it reuses. */
class Settings
{
    public function __construct(private readonly ScopeConfigInterface $config)
    {
    }

    public function appId(): string
    {
        return trim((string)$this->config->getValue('ghostfrog_ebay/ebay_api/ebay_app_id'));
    }

    /** Core's Certificate ID; Magento's config backend returns it decrypted. */
    public function certId(): string
    {
        return trim((string)$this->config->getValue('ghostfrog_ebay/ebay_api/ebay_cert_id'));
    }

    public function ruName(): string
    {
        return trim((string)$this->config->getValue('ghostfrog_ebay/ledger_connection/runame'));
    }

    /** @return string[] */
    public function scopes(): array
    {
        $lines = preg_split('/\s+/', (string)$this->config->getValue('ghostfrog_ebay/ledger_connection/scopes')) ?: [];
        return array_values(array_unique(array_filter(array_map('trim', $lines))));
    }

    public function importEnabled(): bool
    {
        return $this->config->isSetFlag('ghostfrog_ebay/ledger_import/enabled');
    }

    public function firstRunDays(): int
    {
        return max(1, min(90, (int)$this->config->getValue('ghostfrog_ebay/ledger_import/first_run_days') ?: 90));
    }

    public function refreshDays(): int
    {
        return max(1, min(90, (int)$this->config->getValue('ghostfrog_ebay/ledger_import/refresh_days') ?: 30));
    }

    public function skuPrefix(): string
    {
        $prefix = trim((string)$this->config->getValue('ghostfrog_ebay/ledger_products/sku_prefix'));
        return $prefix === '' ? 'GF-' : $prefix;
    }
}
