<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Controller\Adminhtml\StockUnit;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Model\ImageUploader;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

/** Receives a photo from the Receive & Inspect form into a temporary folder until the form is saved. */
class Upload extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_EbayLedger::stock_units';

    public function __construct(Context $context, private readonly ImageUploader $uploader)
    {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        try {
            $result = $this->uploader->saveFileToTmpDir((string)$this->getRequest()->getParam('param_name', 'photos'));
        } catch (\Exception $error) {
            $result = ['error' => $error->getMessage(), 'errorcode' => $error->getCode()];
        }
        return $this->resultFactory->create(ResultFactory::TYPE_JSON)->setData($result);
    }
}
