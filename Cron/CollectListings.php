<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Cron;

use GhostFrog\Ebay\Model\Collection\Collector;
use RuntimeException;

final class CollectListings
{
    public function __construct(private readonly Collector $collector)
    {
    }

    public function execute(): void
    {
        foreach ($this->collector->run() as $result) {
            if ($result['status'] === 'failed') {
                throw new RuntimeException('One or more GhostFrog sources failed. See system.log for details.');
            }
        }
    }
}
