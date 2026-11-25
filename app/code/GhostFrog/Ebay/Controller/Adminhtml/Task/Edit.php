<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Controller\Adminhtml\Task;

use GhostFrog\Ebay\Model\ResourceModel\Task as TaskResource;
use GhostFrog\Ebay\Model\TaskFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\View\Result\PageFactory;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = Index::ADMIN_RESOURCE;

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly TaskFactory $taskFactory,
        private readonly TaskResource $resource
    ) {
        parent::__construct($context);
    }

    public function execute(): Page|Redirect
    {
        $id = (int)$this->getRequest()->getParam('task_id');
        $task = $this->taskFactory->create();
        if ($id > 0) {
            $this->resource->load($task, $id);
            if (!$task->getId()) {
                $this->messageManager->addErrorMessage(__('This task no longer exists.'));
                return $this->resultRedirectFactory->create()->setPath('*/*/index');
            }
        }

        $page = $this->pageFactory->create();
        $page->setActiveMenu(self::ADMIN_RESOURCE);
        $page->getConfig()->getTitle()->prepend(
            $task->getId() ? __('Edit Task: %1', $task->getData('title')) : __('New Task')
        );
        return $page;
    }
}
