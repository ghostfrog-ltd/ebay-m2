<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_Ebay::dashboard';

    public function __construct(Context $context, private readonly PageFactory $pageFactory)
    {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $page = $this->pageFactory->create();
        // Not the ACL id: a menu id ending in ::dashboard gets the core Dashboard icon class.
        $page->setActiveMenu('GhostFrog_Ebay::ghostfrog_dashboard');
        $page->getConfig()->getTitle()->prepend(__('GhostFrog Dashboard'));
        return $page;
    }
}
