<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model;

use Magento\Framework\Model\AbstractModel;

class Pot extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(ResourceModel\Pot::class);
    }
}
