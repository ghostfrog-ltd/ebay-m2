<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\ResourceModel\Grid;

use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/** Accounts with their stock unit count and latest import. Tokens are never selected. */
class AccountCollection extends SearchResult
{
    protected function _initSelect()
    {
        $db = $this->getConnection();
        $units = $db->select()->from(['u' => $this->getTable('ghostfrog_ebayledger_stock_unit')], [new Expression('COUNT(*)')])
            ->where('u.account_id = main_table.account_id');
        $lastRun = $db->select()->from(['r' => $this->getTable('ghostfrog_ebayledger_import_run')], ['run_id'])
            ->where('r.account_id = main_table.account_id')->order('r.run_id DESC')->limit(1);
        $this->getSelect()->from(['main_table' => $this->getMainTable()], [
            'account_id', 'label', 'role', 'enabled', 'status', 'ebay_username', 'access_expires_at',
            'refresh_expires_at', 'purchases_imported_at', 'last_error', 'connected_at',
            'stock_units' => new Expression("($units)"),
        ])->joinLeft(['run' => $this->getTable('ghostfrog_ebayledger_import_run')], "run.run_id = ($lastRun)", [
            'last_import_status' => 'status',
            'last_import_at' => 'finished_at',
            'last_import_message' => 'message',
        ]);
        return $this;
    }
}
