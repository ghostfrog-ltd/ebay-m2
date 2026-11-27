<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Controller\Adminhtml\Sale;

use GhostFrog\EbayLedger\Model\Sale\ImportAll;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;

class Import extends Action implements HttpGetActionInterface, HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_EbayLedger::sales';

    public function __construct(Context $context, private readonly ImportAll $all)
    {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $results = $this->all->run('manual');
        if ($results === []) {
            $this->messageManager->addNoticeMessage(__('No connected seller accounts to import from.'));
        }
        foreach ($results as $label => $result) {
            if (is_string($result)) {
                $this->messageManager->addErrorMessage(__('%1: %2', $label, $result));
                continue;
            }
            $this->messageManager->addSuccessMessage(__('%1: %2 sale lines, %3 new. Orders created: %4, waiting for a match: %5.', $label,
                $result['fetched'], $result['created'], $result['orders']['created'] ?? 0, $result['orders']['waiting'] ?? 0));
        }
        return $this->resultRedirectFactory->create()->setPath('*/*/index');
    }
}
