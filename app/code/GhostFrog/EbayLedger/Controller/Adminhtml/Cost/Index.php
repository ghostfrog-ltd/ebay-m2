<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Controller\Adminhtml\Cost;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_EbayLedger::dashboard';

    public function __construct(Context $context, private readonly PageFactory $pageFactory)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('GhostFrog_EbayLedger::costs');
        $page->getConfig()->getTitle()->prepend(__('My Costs'));
        return $page;
    }
}
