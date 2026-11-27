<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Controller\Adminhtml\Cost;

use GhostFrog\EbayLedger\Model\Cost\Costs;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;

class Delete extends Action implements HttpGetActionInterface, HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_EbayLedger::dashboard';

    public function __construct(Context $context, private readonly Costs $costs)
    {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $this->costs->delete((int)$this->getRequest()->getParam('cost_id'));
        $this->messageManager->addSuccessMessage(__('The cost was deleted.'));
        return $this->resultRedirectFactory->create()->setPath('*/*/index');
    }
}
