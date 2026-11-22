<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ResourceModel\Listing\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

class Collection extends SearchResult
{
    protected function _initSelect()
    {
        $this->getSelect()->from(['main_table' => $this->getMainTable()], [
            'id', 'external_id', 'title', 'source', 'status', 'sale_type', 'price_current',
            'bids_count', 'end_time', 'model_key', 'condition_grade',
        ]);
        return $this;
    }

    protected function _renderOrders()
    {
        // Keep rows with equal sort values in a consistent order across pages.
        $this->_orders += ['id' => self::SORT_ORDER_DESC];
        return parent::_renderOrders();
    }
}
