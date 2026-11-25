<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Controller\Adminhtml\Source;

use Exception;
use GhostFrog\Ebay\Model\ResourceModel\Source;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = Index::ADMIN_RESOURCE;

    public function __construct(
        Context $context,
        private readonly Source $sources,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $id = filter_var($this->getRequest()->getParam('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $this->messageManager->addErrorMessage(__('Select a source to edit.'));
            return $redirect->setPath('*/*/index');
        }

        try {
            $values = $this->getRequest()->getPostValue('source');
            $revision = $this->getRequest()->getPostValue('revision');
            if (!is_array($values) || !is_string($revision)) {
                throw new LocalizedException(__('The source form is incomplete. Please try again.'));
            }
            $this->sources->save($id, $values, $revision);
            $this->messageManager->addSuccessMessage(__('The source was saved.'));
            if ($this->getRequest()->getParam('back') !== 'edit') {
                return $redirect->setPath('*/*/index');
            }
        } catch (NoSuchEntityException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
            return $redirect->setPath('*/*/index');
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (Exception $exception) {
            $this->logger->error('Unable to save eBay source.', ['source_id' => $id, 'exception' => $exception]);
            $this->messageManager->addErrorMessage(__('The source could not be saved. Please try again.'));
        }
        return $redirect->setPath('*/*/edit', ['id' => $id]);
    }
}
