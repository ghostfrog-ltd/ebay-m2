<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Controller\Adminhtml\StockUnit;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_EbayLedger::stock_units';

    public function __construct(Context $context, private readonly PageFactory $pageFactory)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        if (!(int)$this->getRequest()->getParam('stock_unit_id')) {
            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }
        $page = $this->pageFactory->create();
        $page->setActiveMenu('GhostFrog_EbayLedger::stock_units');
        $page->getConfig()->getTitle()->prepend(__('Receive and Inspect'));
        return $page;
    }
}
