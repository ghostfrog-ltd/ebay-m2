<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Task extends AbstractDb
{
    public const TABLE = 'ghostfrog_ebay_tasks';

    protected function _construct(): void
    {
        $this->_init(self::TABLE, 'task_id');
    }
}
