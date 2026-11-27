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

    /** Core's selling fee percent (Comps and Pricing), so Ledger and core estimate fees the same way. */
    public function sellingFeePercent(): float
    {
        return (float)$this->config->getValue('ghostfrog_ebay/comps/selling_fee_percent');
    }

    public function effortCost(): float
    {
        return max(0.0, (float)$this->config->getValue('ghostfrog_ebay/ledger_sell/effort_cost'));
    }

    public function perOrderFee(): float
    {
        return max(0.0, (float)$this->config->getValue('ghostfrog_ebay/ledger_sell/per_order_fee'));
    }

    public function packaging(): float
    {
        return max(0.0, (float)$this->config->getValue('ghostfrog_ebay/ledger_sell/packaging'));
    }

    /** @return array<string, float> Postage by size band. */
    public function postage(): array
    {
        $bands = [];
        foreach (preg_split('/\R/', (string)$this->config->getValue('ghostfrog_ebay/ledger_sell/postage')) ?: [] as $line) {
            if (preg_match('/^\s*([a-z0-9_-]+)\s*:\s*([0-9.]+)\s*$/i', $line, $match)) {
                $bands[strtolower($match[1])] = (float)$match[2];
            }
        }
        return $bands + ['small' => 3.20, 'medium' => 4.50, 'large' => 7.50];
    }

    public function minConfidence(): string
    {
        return (string)$this->config->getValue('ghostfrog_ebay/ledger_sell/min_confidence') ?: 'medium';
    }

    public function compsDays(): int
    {
        return max(7, (int)$this->config->getValue('ghostfrog_ebay/ledger_sell/comps_days') ?: 365);
    }

    public function productTaxClassId(): int
    {
        return (int)$this->config->getValue('ghostfrog_ebay/ledger_orders/tax_class_id');
    }

    public function importedOrderStatus(): string
    {
        return (string)$this->config->getValue('ghostfrog_ebay/ledger_orders/order_status') ?: 'processing';
    }
}
