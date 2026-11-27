<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\ResourceModel\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

class ProductCollection extends SearchResult
{
    protected function _initSelect()
    {
        $this->getSelect()->from(['main_table' => $this->getMainTable()])
            ->joinLeft(['unit' => $this->getTable('ghostfrog_ebayledger_stock_unit')],
                'unit.stock_unit_id = main_table.stock_unit_id',
                ['reference', 'unit_title' => 'title', 'unit_status' => 'status'])
            ->joinLeft(['plan' => $this->getTable('ghostfrog_ebayledger_sell_plan')],
                'plan.plan_id = main_table.plan_id', ['chosen_option']);
        $this->addFilterToMap('status', 'main_table.status');
        $this->addFilterToMap('stock_unit_id', 'main_table.stock_unit_id');
        $this->addFilterToMap('created_at', 'main_table.created_at');
        return $this;
    }
}
