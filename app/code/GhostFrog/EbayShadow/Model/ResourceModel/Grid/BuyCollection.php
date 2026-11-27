<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\ResourceModel\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/** Shadow Buys: every decision, bought or skipped, with its pot and its item's lifecycle stage. */
class BuyCollection extends SearchResult
{
    protected function _initSelect()
    {
        $this->getSelect()->from(['main_table' => $this->getMainTable()], [
            'decision_id', 'pot_id', 'listing_id', 'external_id', 'title', 'url', 'category', 'sale_type',
            'model_key', 'condition_grade', 'grade_confidence', 'comp_count', 'current_price', 'expected_price',
            'max_bid', 'predicted_resale', 'cautious_profit', 'roi_percent', 'cycle_days', 'score', 'outcome',
            'reason', 'evaluations', 'auction_end_at', 'decided_at',
        ])
            ->joinLeft(['p' => $this->getTable('ghostfrog_ebayshadow_pot')], 'p.pot_id = main_table.pot_id',
                ['pot_name' => 'name'])
            ->joinLeft(['i' => $this->getTable('ghostfrog_ebayshadow_item')], 'i.decision_id = main_table.decision_id',
                ['stage', 'price_paid', 'auction_result']);
        $this->addFilterToMap('decision_id', 'main_table.decision_id');
        $this->addFilterToMap('pot_id', 'main_table.pot_id');
        $this->addFilterToMap('listing_id', 'main_table.listing_id');
        $this->addFilterToMap('stage', 'i.stage');
        $this->addFilterToMap('pot_name', 'p.name');
        return $this;
    }

    protected function _renderOrders()
    {
        $this->_orders += ['decided_at' => self::SORT_ORDER_DESC];
        return parent::_renderOrders();
    }
}
