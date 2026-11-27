<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class StockUnit extends AbstractDb
{
    public const TABLE = 'ghostfrog_ebayledger_stock_unit';

    protected function _construct(): void
    {
        $this->_init(self::TABLE, 'stock_unit_id');
    }
}
