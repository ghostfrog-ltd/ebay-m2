<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ResourceModel\Opportunities\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

class Collection extends SearchResult
{
    protected function _initSelect()
    {
        parent::_initSelect();
        $this->getSelect()->where('main_table.eligible_until > UTC_TIMESTAMP()');
        return $this;
    }

    protected function _renderOrders()
    {
        if ($this->_orders === []) {
            $this->_orders = ['expected_profit' => self::SORT_ORDER_DESC];
        }
        $this->_orders += ['listing_id' => self::SORT_ORDER_DESC];
        return parent::_renderOrders();
    }
}
