<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Cron;

use GhostFrog\EbayLedger\Model\Config\Settings;
use GhostFrog\EbayLedger\Model\Sale\ImportAll;

class ImportSales
{
    public function __construct(private readonly Settings $settings, private readonly ImportAll $import)
    {
    }

    public function execute(): void
    {
        if ($this->settings->importEnabled()) {
            $this->import->run('cron');
        }
    }
}
