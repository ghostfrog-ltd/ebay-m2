<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Controller\Adminhtml\Account;

use GhostFrog\EbayLedger\Model\Ebay\SigningKeys;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Throwable;

/** Asks eBay's Key Management API for a signing key, once; Ledger keeps using it until it expires. */
class CreateSigningKey extends Action implements HttpGetActionInterface, HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_EbayLedger::accounts';

    public function __construct(Context $context, private readonly SigningKeys $keys)
    {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $current = $this->keys->current();
        if ($current !== null) {
            $this->messageManager->addNoticeMessage(__('Ledger already has a signing key (%1), valid until %2 UTC.',
                $current['signing_key_id'], $current['expires_at'] ?? __('eBay did not say')));
        } else {
            try {
                $key = $this->keys->create();
                $this->messageManager->addSuccessMessage(__('Signing key %1 created, valid until %2 UTC. Fees and payouts can now be imported.',
                    $key['signing_key_id'] ?? '', $key['expires_at'] ?? __('eBay did not say')));
            } catch (Throwable $error) {
                $this->messageManager->addErrorMessage(__('eBay did not create a signing key: %1', $error->getMessage()));
            }
        }
        return $this->resultRedirectFactory->create()->setPath('*/*/index');
    }
}
