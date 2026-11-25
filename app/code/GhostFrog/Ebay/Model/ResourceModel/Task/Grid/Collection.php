<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ResourceModel\Task\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

class Collection extends SearchResult
{
    protected function _initSelect()
    {
        $this->getSelect()->from(['main_table' => $this->getMainTable()], [
            'task_id', 'title', 'status', 'category', 'created_at', 'updated_at', 'completed_at',
        ]);
        return $this;
    }

    protected function _renderOrders()
    {
        $this->_orders += ['task_id' => self::SORT_ORDER_DESC];
        return parent::_renderOrders();
    }
}
