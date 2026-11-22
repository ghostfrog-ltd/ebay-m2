<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Controller\Adminhtml\Listing;

use Exception;
use GhostFrog\Ebay\Model\ResourceModel\Listing;
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
        private readonly Listing $listings,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $id = filter_var($this->getRequest()->getParam('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $this->messageManager->addErrorMessage(__('Select a listing to edit.'));
            return $redirect->setPath('*/*/index');
        }

        try {
            $values = $this->getRequest()->getPostValue('listing');
            $revision = $this->getRequest()->getPostValue('revision');
            if (!is_array($values) || !is_string($revision)) {
                throw new LocalizedException(__('The listing form is incomplete. Please try again.'));
            }
            $this->listings->save($id, $values, $revision);
            $this->messageManager->addSuccessMessage(__('The listing was saved.'));
        } catch (NoSuchEntityException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
            return $redirect->setPath('*/*/index');
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (Exception $exception) {
            $this->logger->error('Unable to save eBay listing.', ['listing_id' => $id, 'exception' => $exception]);
            $this->messageManager->addErrorMessage(__('The listing could not be saved. Please try again.'));
        }
        return $redirect->setPath('*/*/edit', ['id' => $id]);
    }
}
