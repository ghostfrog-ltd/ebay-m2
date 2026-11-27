<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Controller\Adminhtml\Account;

use GhostFrog\EbayLedger\Model\Ebay\SigningKeys;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_EbayLedger::accounts';

    public function __construct(Context $context, private readonly PageFactory $pageFactory, private readonly SigningKeys $keys)
    {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $key = $this->keys->current();
        if ($key === null) {
            $this->messageManager->addNoticeMessage(__('eBay needs a signing key before Ledger can read your fees and payouts (UK sellers). Press Create Signing Key above, once.'));
        }
        $page = $this->pageFactory->create();
        $page->setActiveMenu('GhostFrog_EbayLedger::accounts');
        $page->getConfig()->getTitle()->prepend(__('eBay Accounts'));
        return $page;
    }
}
