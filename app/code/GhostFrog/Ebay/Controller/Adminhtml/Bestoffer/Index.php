<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Controller\Adminhtml\Bestoffer;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/** Opportunities where the seller accepts Best Offers, working and faulty together. */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_Ebay::opportunities';

    public function __construct(Context $context, private readonly PageFactory $pageFactory)
    {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('GhostFrog_Ebay::best_offer_opportunities');
        $page->getConfig()->getTitle()->prepend(__('Best Offer Opportunities'));
        return $page;
    }
}
