<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\ResourceModel\Grid;

use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/** Pots with their cash balance, the sum of their movements. */
class PotCollection extends SearchResult
{
    protected function _initSelect()
    {
        $movements = $this->getConnection()->select()
            ->from(['m' => $this->getTable('ghostfrog_ebayshadow_cash_movement')], [new Expression('COALESCE(SUM(m.amount), 0)')])
            ->where('m.pot_id = main_table.pot_id');
        $open = $this->getConnection()->select()
            ->from(['i' => $this->getTable('ghostfrog_ebayshadow_item')], [new Expression('COUNT(*)')])
            ->where('i.pot_id = main_table.pot_id')
            ->where("i.stage IN ('bidding', 'inbound', 'received', 'listed', 'outbound')");
        $this->getSelect()->from(['main_table' => $this->getMainTable()], [
            'pot_id', 'name', 'starting_capital', 'start_date', 'status', 'overrides', 'created_at',
            'cash' => new Expression("($movements)"),
            'open_items' => new Expression("($open)"),
        ]);
        return $this;
    }
}
