<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\ResourceModel\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

class CostCollection extends SearchResult
{
    protected function _initSelect()
    {
        $this->getSelect()->from(['main_table' => $this->getMainTable()])
            ->joinLeft(['u' => $this->getTable('ghostfrog_ebayledger_stock_unit')], 'u.stock_unit_id = main_table.stock_unit_id',
                ['reference', 'unit_title' => 'title'])
            ->joinLeft(['p' => $this->getTable('ghostfrog_ebayledger_product')], 'p.ledger_product_id = main_table.ledger_product_id',
                ['sku']);
        foreach (['stock_unit_id', 'cost_type', 'amount', 'incurred_on'] as $field) {
            $this->addFilterToMap($field, 'main_table.' . $field);
        }
        return $this;
    }
}
