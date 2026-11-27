<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Sale;

use GhostFrog\EbayLedger\Model\Account;
use GhostFrog\EbayLedger\Model\Finance\Importer as FinanceImporter;
use GhostFrog\EbayLedger\Model\ResourceModel\Account\CollectionFactory;
use Throwable;

class ImportAll
{
    public function __construct(
        private readonly CollectionFactory $accounts,
        private readonly Importer $importer,
        private readonly FinanceImporter $finances
    ) {
    }

    /** @return array<string, array|string> */
    public function run(string $trigger): array
    {
        $collection = $this->accounts->create()
            ->addFieldToFilter('enabled', 1)
            ->addFieldToFilter('role', ['in' => [Account::ROLE_SELLER, Account::ROLE_BOTH]])
            ->addFieldToFilter('refresh_token', ['notnull' => true]);
        $results = [];
        foreach ($collection as $account) {
            $label = (string)$account->getData('label');
            try {
                $results[$label] = $this->importer->run($account, $trigger);
            } catch (Throwable $error) {
                $results[$label] = $error->getMessage();
            }
            // Fees and payouts follow the sales, so new sales have their fees linked straight away.
            try {
                $results[$label . ' fees'] = $this->finances->run($account, $trigger);
            } catch (Throwable $error) {
                $results[$label . ' fees'] = $error->getMessage();
            }
        }
        return $results;
    }
}
