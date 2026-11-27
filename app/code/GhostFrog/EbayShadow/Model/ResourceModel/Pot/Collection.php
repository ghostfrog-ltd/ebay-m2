<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\ResourceModel\Pot;

use GhostFrog\EbayShadow\Model\Pot;
use GhostFrog\EbayShadow\Model\ResourceModel\Pot as PotResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'pot_id';

    protected function _construct()
    {
        $this->_init(Pot::class, PotResource::class);
    }
}
