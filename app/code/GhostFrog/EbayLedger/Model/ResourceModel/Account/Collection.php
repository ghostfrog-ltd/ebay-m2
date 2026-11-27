<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\ResourceModel\Account;

use GhostFrog\EbayLedger\Model\Account;
use GhostFrog\EbayLedger\Model\ResourceModel\Account as AccountResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'account_id';

    protected function _construct(): void
    {
        $this->_init(Account::class, AccountResource::class);
    }
}
