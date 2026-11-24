<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Cron;

use GhostFrog\Ebay\Model\Notification\Worker as Notifications;
use GhostFrog\Ebay\Model\PriceRefresh\Worker as PriceRefresh;

final class MonitorOpportunities
{
    public function __construct(
        private readonly PriceRefresh $refresh,
        private readonly Notifications $notifications
    ) {
    }

    public function execute(): void
    {
        try {
            $this->refresh->execute();
        } finally {
            $this->notifications->execute();
        }
    }
}
