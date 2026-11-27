<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Controller\Adminhtml\Pots;

use GhostFrog\EbayShadow\Model\Config\Source\PotStatus;
use GhostFrog\EbayShadow\Model\ResourceModel\Pot as PotResource;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Redirect;

class MassStatus extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_EbayShadow::pots';

    public function __construct(Context $context, private readonly ResourceConnection $resource)
    {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $status = (string)$this->getRequest()->getParam('status');
        $ids = array_filter(array_map('intval', (array)$this->getRequest()->getParam('selected', [])));
        // "Select all" in the grid sends excluded=false instead of a list of IDs.
        $all = $this->getRequest()->getParam('excluded') === 'false';
        if (in_array($status, [PotStatus::RUNNING, PotStatus::PAUSED, PotStatus::CLOSED], true) && ($ids !== [] || $all)) {
            $count = $this->resource->getConnection()->update($this->resource->getTableName(PotResource::TABLE),
                ['status' => $status], $all ? [] : ['pot_id IN (?)' => $ids]);
            $this->messageManager->addSuccessMessage(__('%1 pot(s) updated.', $count));
        }
        return $this->resultRedirectFactory->create()->setPath('*/*/index');
    }
}
