<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Cron;

use GhostFrog\EbayLedger\Model\Config\Settings;
use GhostFrog\EbayLedger\Model\Purchase\ImportAll;

class ImportPurchases
{
    public function __construct(private readonly Settings $settings, private readonly ImportAll $import)
    {
    }

    public function execute(): void
    {
        if ($this->settings->importEnabled()) {
            // Each account's failure is recorded on its import run and account; one account never stops another.
            $this->import->run('cron');
        }
    }
}
