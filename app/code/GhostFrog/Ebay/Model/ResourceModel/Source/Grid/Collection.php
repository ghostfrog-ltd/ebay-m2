<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ResourceModel\Source\Grid;

use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

class Collection extends SearchResult
{
    protected function _initSelect()
    {
        $enabled = new Expression('COALESCE(main_table.enabled, 0)');
        $minutes = new Expression('ROUND(main_table.scrape_interval_seconds / 60)');
        $this->getSelect()->from(['main_table' => $this->getMainTable()], [
            'id', 'name', 'domain', 'niche', 'enabled' => $enabled, 'interval_minutes' => $minutes,
            'last_scraped_at', 'updated_at_utc',
        ]);
        // A NULL flag means collection skips the source, so filter it as disabled.
        $this->addFilterToMap('enabled', $enabled);
        $this->addFilterToMap('interval_minutes', $minutes);
        $this->addFilterToMap('id', 'main_table.id');
        return $this;
    }

    protected function _renderOrders()
    {
        $this->_orders += ['id' => self::SORT_ORDER_ASC];
        return parent::_renderOrders();
    }
}
