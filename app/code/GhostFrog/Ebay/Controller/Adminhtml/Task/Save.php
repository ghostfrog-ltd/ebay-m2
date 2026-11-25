<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Controller\Adminhtml\Task;

use Exception;
use GhostFrog\Ebay\Model\ResourceModel\Task as TaskResource;
use GhostFrog\Ebay\Model\TaskFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = Index::ADMIN_RESOURCE;
    public const PERSIST_KEY = 'ghostfrog_ebay_task';
    private const FIELDS = ['title', 'description', 'status', 'category'];

    public function __construct(
        Context $context,
        private readonly TaskFactory $taskFactory,
        private readonly TaskResource $resource,
        private readonly DataPersistorInterface $persistor,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $data = $this->getRequest()->getPostValue();
        if (!is_array($data) || $data === []) {
            return $redirect->setPath('*/*/index');
        }

        $id = (int)($data['task_id'] ?? 0);
        $task = $this->taskFactory->create();
        if ($id > 0) {
            $this->resource->load($task, $id);
            if (!$task->getId()) {
                $this->messageManager->addErrorMessage(__('This task no longer exists.'));
                return $redirect->setPath('*/*/index');
            }
        }

        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $task->setData($field, is_scalar($data[$field]) ? (string)$data[$field] : null);
            }
        }

        try {
            $this->resource->save($task);
            $this->persistor->clear(self::PERSIST_KEY);
            $this->messageManager->addSuccessMessage(__('The task was saved.'));
            if ($this->getRequest()->getParam('back') === 'continue') {
                return $redirect->setPath('*/*/edit', ['task_id' => $task->getId()]);
            }
            return $redirect->setPath('*/*/index');
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (Exception $exception) {
            $this->logger->error('Unable to save GhostFrog task.', ['task_id' => $id, 'exception' => $exception]);
            $this->messageManager->addErrorMessage(__('The task could not be saved. Please try again.'));
        }

        // Keep what was typed so a validation error does not lose the description.
        $this->persistor->set(self::PERSIST_KEY, array_intersect_key($data, array_flip(self::FIELDS)));
        return $id > 0 ? $redirect->setPath('*/*/edit', ['task_id' => $id]) : $redirect->setPath('*/*/new');
    }
}
