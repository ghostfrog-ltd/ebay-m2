<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\ResourceModel\StockUnit;

use GhostFrog\EbayLedger\Model\ResourceModel\StockUnit as StockUnitResource;
use GhostFrog\EbayLedger\Model\StockUnit;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'stock_unit_id';

    protected function _construct(): void
    {
        $this->_init(StockUnit::class, StockUnitResource::class);
    }
}
