<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Controller\Adminhtml\Account;

use GhostFrog\EbayLedger\Model\Account\Tokens;
use GhostFrog\EbayLedger\Model\Purchase\ImportAll;
use GhostFrog\EbayLedger\Model\Purchase\Importer;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Throwable;

/** "Import now": one account when account_id is given, otherwise every connected buyer account. */
class Import extends Action implements HttpGetActionInterface, HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_EbayLedger::stock_units';

    public function __construct(
        Context $context,
        private readonly Tokens $tokens,
        private readonly Importer $importer,
        private readonly ImportAll $all
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $id = (int)$this->getRequest()->getParam('account_id');
        try {
            if ($id > 0) {
                $account = $this->tokens->load($id);
                $results = [(string)$account->getData('label') => $this->runOne(fn() => $this->importer->run($account, 'manual'))];
            } else {
                $results = $this->all->run('manual');
            }
        } catch (Throwable $error) {
            $results = ['' => $error->getMessage()];
        }
        if ($results === []) {
            $this->messageManager->addNoticeMessage(__('No connected buyer accounts to import from.'));
        }
        foreach ($results as $label => $result) {
            if (is_string($result)) {
                $this->messageManager->addErrorMessage(__('%1: %2', $label, $result));
            } else {
                $this->messageManager->addSuccessMessage(__('%1: %2 new stock units, %3 updated, %4 unchanged.',
                    $label, $result['created'], $result['updated'], $result['unchanged']));
            }
        }
        $back = (string)$this->getRequest()->getParam('back');
        return $this->resultRedirectFactory->create()->setPath($back === 'account' && $id
            ? 'ghostfrog_ledger/account/edit' : 'ghostfrog_ledger/stockunit/index', $back === 'account' && $id ? ['account_id' => $id] : []);
    }

    private function runOne(callable $run): array|string
    {
        try {
            return $run();
        } catch (Throwable $error) {
            return $error->getMessage();
        }
    }
}
