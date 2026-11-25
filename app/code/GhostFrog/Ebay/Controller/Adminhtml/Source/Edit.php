<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Controller\Adminhtml\Source;

use GhostFrog\Ebay\Model\ResourceModel\Source;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = Index::ADMIN_RESOURCE;

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly Source $sources
    ) {
        parent::__construct($context);
    }

    public function execute(): Page|Redirect
    {
        $id = filter_var($this->getRequest()->getParam('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        try {
            if ($id === false) {
                throw new NoSuchEntityException(__('Select a source to edit.'));
            }
            $source = $this->sources->get($id);
        } catch (NoSuchEntityException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }

        $page = $this->pageFactory->create();
        $page->setActiveMenu(self::ADMIN_RESOURCE);
        $page->getConfig()->getTitle()->prepend(__('Edit Source: %1', $source['name']));
        $page->getLayout()->getBlock('ghostfrog.ebay.source.edit')->addData([
            'source' => $source,
            'search' => Source::search($source['domain']),
            'revision' => $this->sources->revision($source),
        ]);
        return $page;
    }
}
