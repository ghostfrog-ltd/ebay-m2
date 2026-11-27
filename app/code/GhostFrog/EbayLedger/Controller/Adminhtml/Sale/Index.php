<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Controller\Adminhtml\Sale;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_EbayLedger::sales';

    public function __construct(Context $context, private readonly PageFactory $pageFactory, private readonly ResourceConnection $resource)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $db = $this->resource->getConnection();
        $unmatched = (int)$db->fetchOne($db->select()->from($this->resource->getTableName('ghostfrog_ebayledger_sale_line'), ['COUNT(*)'])
            ->where('match_status = ?', 'unmatched'));
        if ($unmatched > 0) {
            $this->messageManager->addNoticeMessage(__('%1 sale(s) are unmatched. Filter Match by "Unmatched", then Link each to its product or Ignore it if it is not a Ledger item.', $unmatched));
        }
        $page = $this->pageFactory->create();
        $page->setActiveMenu('GhostFrog_EbayLedger::sales');
        $page->getConfig()->getTitle()->prepend(__('eBay Sales'));
        return $page;
    }
}
