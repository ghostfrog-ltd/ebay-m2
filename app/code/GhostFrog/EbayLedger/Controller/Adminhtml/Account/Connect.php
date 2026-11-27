<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Controller\Adminhtml\Account;

use GhostFrog\EbayLedger\Model\Account\ConsentState;
use GhostFrog\EbayLedger\Model\Account\Tokens;
use GhostFrog\EbayLedger\Model\Ebay\OAuth;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Throwable;

/** Sends you to eBay to sign in to the account and allow Ledger to read it. */
class Connect extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_EbayLedger::accounts';

    public function __construct(
        Context $context,
        private readonly Tokens $tokens,
        private readonly OAuth $oauth,
        private readonly ConsentState $state
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $id = (int)$this->getRequest()->getParam('account_id');
        try {
            $account = $this->tokens->load($id);
            return $redirect->setUrl($this->oauth->authorizeUrl($this->state->issue((int)$account->getId())));
        } catch (Throwable $error) {
            $this->messageManager->addErrorMessage($error->getMessage());
            return $redirect->setPath('*/*/edit', ['account_id' => $id]);
        }
    }
}
