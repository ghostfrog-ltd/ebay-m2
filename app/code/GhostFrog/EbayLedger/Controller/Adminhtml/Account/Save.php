<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Controller\Adminhtml\Account;

use GhostFrog\EbayLedger\Model\Account;
use GhostFrog\EbayLedger\Model\AccountFactory;
use GhostFrog\EbayLedger\Model\ResourceModel\Account as AccountResource;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Throwable;

/** Saves an account's label, role and whether it imports. Tokens only change through Connect. */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_EbayLedger::accounts';

    public function __construct(
        Context $context,
        private readonly AccountFactory $accounts,
        private readonly AccountResource $resource
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $data = (array)$this->getRequest()->getPostValue();
        $id = (int)($data['account_id'] ?? 0);
        $account = $this->accounts->create();
        if ($id > 0) {
            $this->resource->load($account, $id);
            if (!$account->getId()) {
                $this->messageManager->addErrorMessage(__('This account no longer exists.'));
                return $redirect->setPath('*/*/index');
            }
        }
        $label = trim((string)($data['label'] ?? ''));
        $role = (string)($data['role'] ?? '');
        if ($label === '' || !in_array($role, [Account::ROLE_BUYER, Account::ROLE_SELLER, Account::ROLE_BOTH], true)) {
            $this->messageManager->addErrorMessage(__('Enter a name and choose whether the account buys, sells or both.'));
            return $redirect->setPath('*/*/edit', $id ? ['account_id' => $id] : []);
        }
        $account->addData([
            'label' => mb_substr($label, 0, 100),
            'role' => $role,
            'enabled' => (int)!empty($data['enabled']),
        ]);
        try {
            $this->resource->save($account);
            $this->messageManager->addSuccessMessage($account->getData('refresh_token')
                ? __('The account was saved.')
                : __('The account was saved. Click Connect to eBay to sign in to it.'));
        } catch (Throwable $error) {
            $this->messageManager->addErrorMessage(__('The account could not be saved: %1', $error->getMessage()));
            return $redirect->setPath('*/*/edit', $id ? ['account_id' => $id] : []);
        }
        return $redirect->setPath('*/*/edit', ['account_id' => $account->getId()]);
    }
}
