<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Controller\Adminhtml\Task;

use Exception;
use GhostFrog\Ebay\Model\ResourceModel\Task as TaskResource;
use GhostFrog\Ebay\Model\TaskFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = Index::ADMIN_RESOURCE;

    public function __construct(
        Context $context,
        private readonly TaskFactory $taskFactory,
        private readonly TaskResource $resource,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/index');
        $task = $this->taskFactory->create();
        $this->resource->load($task, (int)$this->getRequest()->getParam('task_id'));
        if (!$task->getId()) {
            $this->messageManager->addErrorMessage(__('This task no longer exists.'));
            return $redirect;
        }
        try {
            $this->resource->delete($task);
            $this->messageManager->addSuccessMessage(__('The task was deleted.'));
        } catch (Exception $exception) {
            $this->logger->error('Unable to delete GhostFrog task.', ['exception' => $exception]);
            $this->messageManager->addErrorMessage(__('The task could not be deleted. Please try again.'));
        }
        return $redirect;
    }
}
