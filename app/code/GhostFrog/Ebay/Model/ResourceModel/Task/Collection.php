<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ResourceModel\Task;

use GhostFrog\Ebay\Model\ResourceModel\Task as TaskResource;
use GhostFrog\Ebay\Model\Task;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'task_id';

    protected function _construct(): void
    {
        $this->_init(Task::class, TaskResource::class);
    }
}
