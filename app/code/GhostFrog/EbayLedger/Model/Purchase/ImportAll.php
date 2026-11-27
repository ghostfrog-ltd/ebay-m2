<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Purchase;

use GhostFrog\EbayLedger\Model\Account;
use GhostFrog\EbayLedger\Model\ResourceModel\Account\CollectionFactory;
use Throwable;

/** Runs the purchase import for every enabled, connected buyer account. */
class ImportAll
{
    public function __construct(
        private readonly CollectionFactory $accounts,
        private readonly Importer $importer
    ) {
    }

    /** @return array<string, array|string> Result or error message by account label. */
    public function run(string $trigger): array
    {
        $collection = $this->accounts->create()
            ->addFieldToFilter('enabled', 1)
            ->addFieldToFilter('role', ['in' => [Account::ROLE_BUYER, Account::ROLE_BOTH]])
            ->addFieldToFilter('refresh_token', ['notnull' => true]);
        $results = [];
        foreach ($collection as $account) {
            try {
                $results[(string)$account->getData('label')] = $this->importer->run($account, $trigger);
            } catch (Throwable $error) {
                $results[(string)$account->getData('label')] = $error->getMessage();
            }
        }
        return $results;
    }
}
