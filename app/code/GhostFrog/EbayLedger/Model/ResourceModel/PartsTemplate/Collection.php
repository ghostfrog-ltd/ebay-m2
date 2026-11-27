<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\ResourceModel\PartsTemplate;

use GhostFrog\EbayLedger\Model\PartsTemplate;
use GhostFrog\EbayLedger\Model\ResourceModel\PartsTemplate as Resource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'template_id';

    protected function _construct(): void
    {
        $this->_init(PartsTemplate::class, Resource::class);
    }
}
