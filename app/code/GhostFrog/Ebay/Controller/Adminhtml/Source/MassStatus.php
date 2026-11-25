<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Controller\Adminhtml\Source;

use Exception;
use GhostFrog\Ebay\Model\ResourceModel\Source;
use GhostFrog\Ebay\Ui\MassAction\SelectedIds;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/** Enables or disables the sources selected in the grid, honouring its filters for "Select All". */
class MassStatus extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = Index::ADMIN_RESOURCE;

    public function __construct(
        Context $context,
        private readonly SelectedIds $selectedIds,
        private readonly Source $sources,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/index');
        $enabled = $this->getRequest()->getParam('enabled');
        if (!in_array($enabled, ['0', '1'], true)) {
            $this->messageManager->addErrorMessage(__('Choose whether to enable or disable the sources.'));
            return $redirect;
        }

        try {
            $ids = $this->selectedIds->get();
            $changed = $this->sources->setEnabled($ids, $enabled === '1');
            $this->messageManager->addSuccessMessage($enabled === '1'
                ? __('%1 of %2 selected source(s) enabled.', $changed, count($ids))
                : __('%1 of %2 selected source(s) disabled.', $changed, count($ids)));
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (Exception $exception) {
            $this->logger->error('Unable to change eBay source status.', ['exception' => $exception]);
            $this->messageManager->addErrorMessage(__('The sources could not be updated. Please try again.'));
        }
        return $redirect;
    }
}
