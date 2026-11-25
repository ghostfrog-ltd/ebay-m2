<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Controller\Adminhtml\Task;

use Exception;
use GhostFrog\Ebay\Model\ResourceModel\Task\CollectionFactory;
use GhostFrog\Ebay\Model\Task\Status;
use GhostFrog\Ebay\Ui\MassAction\SelectedIds;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/** Sets the status of the selected tasks through the model so completion dates stay correct. */
class MassStatus extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = Index::ADMIN_RESOURCE;

    public function __construct(
        Context $context,
        private readonly SelectedIds $selectedIds,
        private readonly CollectionFactory $collectionFactory,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/index');
        $status = (string)$this->getRequest()->getParam('status');
        if (!isset(Status::LABELS[$status])) {
            $this->messageManager->addErrorMessage(__('Select a valid status.'));
            return $redirect;
        }

        try {
            $ids = $this->selectedIds->get();
            $changed = 0;
            if ($ids !== []) {
                foreach ($this->collectionFactory->create()->addFieldToFilter('task_id', ['in' => $ids]) as $task) {
                    if ($task->getData('status') !== $status) {
                        $task->setData('status', $status)->save();
                        $changed++;
                    }
                }
            }
            $this->messageManager->addSuccessMessage(__(
                '%1 of %2 selected task(s) set to %3.',
                $changed,
                count($ids),
                __(Status::LABELS[$status])
            ));
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (Exception $exception) {
            $this->logger->error('Unable to change GhostFrog task status.', ['exception' => $exception]);
            $this->messageManager->addErrorMessage(__('The tasks could not be updated. Please try again.'));
        }
        return $redirect;
    }
}
