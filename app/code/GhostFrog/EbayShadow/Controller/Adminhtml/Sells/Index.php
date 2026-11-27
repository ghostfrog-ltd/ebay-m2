<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Controller\Adminhtml\Sells;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_EbayShadow::sells';

    public function __construct(Context $context, private readonly PageFactory $pageFactory)
    {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('GhostFrog_EbayShadow::sells');
        $page->getConfig()->getTitle()->prepend(__('Shadow Sells'));
        return $page;
    }
}
