<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Setup\Patch\Data;

use GhostFrog\Ebay\Model\Comps\Index as CompsIndex;
use GhostFrog\Ebay\Model\Opportunities\Index as OpportunitiesIndex;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\NonTransactionableInterface;

class InitializeOpportunityIndexing implements DataPatchInterface, NonTransactionableInterface
{
    public function __construct(private readonly IndexerRegistry $indexers)
    {
    }

    public function apply(): void
    {
        $comps = $this->indexers->get(CompsIndex::ID);
        // The previous comps view had no subscriptions. Recreate them for pre-existing scheduled installs.
        if ($comps->isScheduled()) {
            $comps->getView()->unsubscribe(false);
        }
        $comps->setScheduled(true);

        $opportunities = $this->indexers->get(OpportunitiesIndex::ID);
        $opportunities->setScheduled(true);

        $comps->invalidate();
        $opportunities->invalidate();
        $comps->reindexAll();
        $opportunities->reindexAll();
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
