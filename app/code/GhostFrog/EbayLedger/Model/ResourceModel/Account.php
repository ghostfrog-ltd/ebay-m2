<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Account extends AbstractDb
{
    public const TABLE = 'ghostfrog_ebayledger_account';

    protected function _construct(): void
    {
        $this->_init(self::TABLE, 'account_id');
    }
}
