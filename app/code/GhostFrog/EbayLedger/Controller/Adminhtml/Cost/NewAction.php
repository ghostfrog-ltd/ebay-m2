<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Controller\Adminhtml\Cost;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;

class NewAction extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_EbayLedger::dashboard';

    public function execute()
    {
        return $this->resultRedirectFactory->create()->setPath('*/*/edit',
            array_filter(['stock_unit_id' => (int)$this->getRequest()->getParam('stock_unit_id')]));
    }
}
