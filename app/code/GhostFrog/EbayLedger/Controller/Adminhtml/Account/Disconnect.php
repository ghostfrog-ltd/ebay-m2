<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Controller\Adminhtml\Account;

use GhostFrog\EbayLedger\Model\Account\Tokens;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Throwable;

/** Forgets the account's eBay tokens. Its stock units and history stay. */
class Disconnect extends Action implements HttpGetActionInterface, HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_EbayLedger::accounts';

    public function __construct(Context $context, private readonly Tokens $tokens)
    {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $id = (int)$this->getRequest()->getParam('account_id');
        try {
            $this->tokens->disconnect($this->tokens->load($id));
            $this->messageManager->addSuccessMessage(__('Ledger has forgotten this account\'s eBay sign-in. To remove its access on eBay too, use eBay\'s account settings → Third-party app access.'));
        } catch (Throwable $error) {
            $this->messageManager->addErrorMessage($error->getMessage());
        }
        return $this->resultRedirectFactory->create()->setPath('*/*/edit', ['account_id' => $id]);
    }
}
