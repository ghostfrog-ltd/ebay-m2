<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Pot extends AbstractDb
{
    public const TABLE = 'ghostfrog_ebayshadow_pot';

    protected function _construct()
    {
        $this->_init(self::TABLE, 'pot_id');
    }
}
