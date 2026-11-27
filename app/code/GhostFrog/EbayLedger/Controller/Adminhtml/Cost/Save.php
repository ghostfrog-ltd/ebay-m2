<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Controller\Adminhtml\Cost;

use GhostFrog\EbayLedger\Model\Cost\Costs;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Throwable;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_EbayLedger::dashboard';

    public function __construct(Context $context, private readonly Costs $costs)
    {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $data = (array)$this->getRequest()->getPostValue();
        try {
            $this->costs->save($data);
            $this->messageManager->addSuccessMessage(__('The cost was saved.'));
        } catch (Throwable $error) {
            $this->messageManager->addErrorMessage($error->getMessage());
            return $this->resultRedirectFactory->create()->setPath('*/*/edit', array_filter(['cost_id' => (int)($data['cost_id'] ?? 0)]));
        }
        return $this->resultRedirectFactory->create()->setPath('*/*/index');
    }
}
