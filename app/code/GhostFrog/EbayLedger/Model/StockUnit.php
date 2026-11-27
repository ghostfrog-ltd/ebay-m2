<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model;

use Magento\Framework\Model\AbstractModel;

class StockUnit extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(ResourceModel\StockUnit::class);
    }
}
