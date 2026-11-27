<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Controller\Adminhtml\Sale;

use GhostFrog\EbayLedger\Model\Sale\OrderSync;
use GhostFrog\EbayLedger\Model\Sale\Store;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Throwable;

/** Links an unmatched sale to a product, or ignores it, then creates the Magento order if nothing else waits. */
class LinkPost extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_EbayLedger::sales';

    public function __construct(Context $context, private readonly Store $store, private readonly OrderSync $sync)
    {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $request = $this->getRequest();
        $lineId = (int)$request->getParam('sale_line_id');
        try {
            $orderId = match ((string)$request->getParam('do')) {
                'ignore' => $this->store->ignore($lineId, true),
                'unignore' => $this->store->ignore($lineId, false),
                default => $this->store->link($lineId, (int)$request->getParam('ledger_product_id')),
            };
            $outcome = $this->sync->sync($orderId);
            $this->messageManager->addSuccessMessage(match ($outcome) {
                'created' => __('Saved, and Magento order created for eBay order %1.', $orderId),
                'waiting' => __('Saved. eBay order %1 has other unmatched lines, so its Magento order waits for them.', $orderId),
                default => __('Saved.'),
            });
        } catch (Throwable $error) {
            $this->messageManager->addErrorMessage($error->getMessage());
            return $this->resultRedirectFactory->create()->setPath('*/*/link', ['sale_line_id' => $lineId]);
        }
        return $this->resultRedirectFactory->create()->setPath('*/*/index');
    }
}
