<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\ResourceModel\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

class SaleCollection extends SearchResult
{
    protected function _initSelect()
    {
        $this->getSelect()->from(['main_table' => $this->getMainTable()], [
            'sale_line_id', 'account_id', 'ebay_order_id', 'ebay_line_item_id', 'ebay_item_id', 'sku', 'title',
            'quantity', 'line_price', 'postage', 'refunded', 'sold_at', 'payment_status', 'fulfillment_status',
            'cancelled', 'match_status', 'match_note', 'ledger_product_id', 'magento_order_id', 'shipped', 'last_error',
        ])->joinLeft(['p' => $this->getTable('ghostfrog_ebayledger_product')], 'p.ledger_product_id = main_table.ledger_product_id',
            ['matched_sku' => 'sku'])
            ->joinLeft(['o' => $this->getTable('sales_order')], 'o.entity_id = main_table.magento_order_id',
                ['order_increment_id' => 'increment_id', 'order_status' => 'status']);
        foreach (['sku', 'title', 'sold_at', 'match_status', 'account_id', 'ebay_order_id'] as $field) {
            $this->addFilterToMap($field, 'main_table.' . $field);
        }
        return $this;
    }
}
