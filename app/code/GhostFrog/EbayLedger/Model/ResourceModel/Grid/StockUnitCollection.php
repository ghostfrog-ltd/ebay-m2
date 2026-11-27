<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\ResourceModel\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

class StockUnitCollection extends SearchResult
{
    protected function _initSelect()
    {
        $this->getSelect()->from(['main_table' => $this->getMainTable()], [
            'stock_unit_id', 'reference', 'account_id', 'ebay_order_id', 'ebay_line_item_id', 'ebay_transaction_id',
            'ebay_item_id', 'unit_index', 'line_quantity', 'title', 'currency', 'item_price', 'postage_paid',
            'other_costs', 'total_cost', 'purchase_method', 'purchased_at', 'paid_at', 'shipped_at',
            'ebay_order_status', 'status', 'listing_id', 'model_key', 'predicted_grade', 'predicted_resale',
            'predicted_comp_count', 'imported_at',
        ])->joinLeft(['account' => $this->getTable('ghostfrog_ebayledger_account')],
            'account.account_id = main_table.account_id', ['account_label' => 'label']);
        $this->addFilterToMap('account_id', 'main_table.account_id');
        $this->addFilterToMap('status', 'main_table.status');
        return $this;
    }
}
