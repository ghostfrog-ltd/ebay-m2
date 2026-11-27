<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class PartsTemplate extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ghostfrog_ebayledger_parts_template', 'template_id');
    }
}
