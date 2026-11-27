<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Controller\Adminhtml\StockUnit;

use GhostFrog\EbayLedger\Model\ResourceModel\StockUnit as StockUnitResource;
use GhostFrog\EbayLedger\Model\StockUnit\Inspection;
use GhostFrog\EbayLedger\Model\StockUnit\Photos;
use GhostFrog\EbayLedger\Model\StockUnit\Status;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Throwable;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_EbayLedger::stock_units';

    public function __construct(
        Context $context,
        private readonly StockUnitResource $resource,
        private readonly Photos $photos
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $data = (array)$this->getRequest()->getPostValue();
        $id = (int)($data['stock_unit_id'] ?? 0);
        $db = $this->resource->getConnection();
        $unit = $db->fetchRow($db->select()->from($this->resource->getMainTable())->where('stock_unit_id = ?', $id));
        if (!$unit) {
            $this->messageManager->addErrorMessage(__('This stock unit no longer exists.'));
            return $redirect->setPath('*/*/index');
        }
        try {
            $data['photos'] = $this->photos->keep((array)($data['photos'] ?? []));
            $changes = Inspection::apply($unit, $data);
            $db->update($this->resource->getMainTable(), $changes, ['stock_unit_id = ?' => $id]);
            $this->messageManager->addSuccessMessage(__('%1 saved: %2.', $unit['reference'],
                __(Status::LABELS[$changes['status'] ?? $unit['status']] ?? '')));
        } catch (Throwable $error) {
            $this->messageManager->addErrorMessage(__('%1 was not saved: %2', $unit['reference'], $error->getMessage()));
            return $redirect->setPath('*/*/edit', ['stock_unit_id' => $id]);
        }
        return $this->getRequest()->getParam('back')
            ? $redirect->setPath('*/*/edit', ['stock_unit_id' => $id])
            : $redirect->setPath('*/*/index');
    }
}
