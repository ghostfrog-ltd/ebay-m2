<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Observer;

use GhostFrog\Ebay\Model\Comps\Pricing;
use GhostFrog\Ebay\Model\Opportunities\Index;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Indexer\IndexerRegistry;

class InvalidateOpportunities implements ObserverInterface
{
    public function __construct(private readonly IndexerRegistry $indexers)
    {
    }

    public function execute(Observer $observer): void
    {
        foreach ($observer->getEvent()->getData('changed_paths') ?? [] as $path) {
            if (Pricing::isSettingPath($path)) {
                $this->indexers->get(Index::ID)->invalidate();
                return;
            }
        }
    }
}
