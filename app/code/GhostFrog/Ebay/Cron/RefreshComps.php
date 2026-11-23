<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Cron;

use GhostFrog\Ebay\Model\Comps\Index;
use GhostFrog\Ebay\Model\Opportunities\Index as OpportunitiesIndex;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Indexer\StateInterface;

final class RefreshComps
{
    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly IndexerRegistry $indexers
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isSetFlag('ghostfrog_ebay/general/enabled')) {
            return;
        }

        foreach ([Index::ID, OpportunitiesIndex::ID] as $id) {
            $indexer = $this->indexers->get($id);
            if ($indexer->getStatus() === StateInterface::STATUS_VALID) {
                $indexer->invalidate();
            }
        }
    }
}
