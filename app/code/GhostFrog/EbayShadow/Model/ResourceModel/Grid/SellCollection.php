<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\ResourceModel\Grid;

use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Shadow Sells: one row per won item, sold or not yet. Every row here is simulated (source "shadow"); real
 * flips from GhostFrog_EbayLedger may be shown beside them later but are never stored in shadow's tables.
 */
class SellCollection extends SearchResult
{
    protected function _initSelect()
    {
        $this->getSelect()->from(['main_table' => $this->getMainTable()], [
            'item_id', 'decision_id', 'pot_id', 'listing_id', 'category', 'stage', 'stale', 'price_paid', 'buy_cost',
            'bought_at', 'listed_at', 'sold_at', 'paid_out_at', 'banked_at',
            'source' => new Expression("'shadow'"),
        ])
            ->join(['d' => $this->getTable('ghostfrog_ebayshadow_decision')], 'd.decision_id = main_table.decision_id',
                ['title', 'url', 'external_id', 'condition_grade', 'predicted_resale', 'cautious_profit'])
            ->joinLeft(['p' => $this->getTable('ghostfrog_ebayshadow_pot')], 'p.pot_id = main_table.pot_id',
                ['pot_name' => 'name'])
            ->joinLeft(['s' => $this->getTable('ghostfrog_ebayshadow_sale')],
                "s.item_id = main_table.item_id AND s.part_key = 'whole'",
                ['sale_id', 'method', 'sell_price', 'fees', 'postage', 'packaging', 'returns_cost', 'net_proceeds',
                    'realised_profit', 'error_percent', 'days_to_sell', 'forward_sales', 'resolved_at'])
            ->where("main_table.stage NOT IN ('bidding', 'lost', 'void')");
        foreach (['item_id', 'decision_id', 'pot_id', 'listing_id', 'category', 'stage', 'stale', 'buy_cost'] as $field) {
            $this->addFilterToMap($field, 'main_table.' . $field);
        }
        foreach (['predicted_resale', 'cautious_profit', 'title', 'condition_grade'] as $field) {
            $this->addFilterToMap($field, 'd.' . $field);
        }
        foreach (['method', 'sell_price', 'realised_profit', 'error_percent', 'days_to_sell'] as $field) {
            $this->addFilterToMap($field, 's.' . $field);
        }
        $this->addFilterToMap('pot_name', 'p.name');
        return $this;
    }

    protected function _renderOrders()
    {
        $this->_orders += ['item_id' => self::SORT_ORDER_DESC];
        return parent::_renderOrders();
    }
}
