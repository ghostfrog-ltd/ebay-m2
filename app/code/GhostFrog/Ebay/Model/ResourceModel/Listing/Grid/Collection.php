<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ResourceModel\Listing\Grid;

use GhostFrog\Ebay\Model\ResourceModel\Source;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface as FetchStrategy;
use Magento\Framework\Data\Collection\EntityFactoryInterface as EntityFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface as Logger;

class Collection extends SearchResult
{
    private ResourceConnection $resourceConnection;

    public function __construct(
        EntityFactory $entityFactory,
        Logger $logger,
        FetchStrategy $fetchStrategy,
        EventManager $eventManager,
        ResourceConnection $resourceConnection,
        $mainTable,
        $resourceModel = null,
        $identifierName = null,
        $connectionName = null
    ) {
        // Set before the parent constructor, which runs _initSelect().
        $this->resourceConnection = $resourceConnection;
        parent::__construct(
            $entityFactory, $logger, $fetchStrategy, $eventManager,
            $mainTable, $resourceModel, $identifierName, $connectionName
        );
    }

    protected function _initSelect()
    {
        $this->getSelect()->from(['main_table' => $this->getMainTable()], [
            'id', 'external_id', 'title', 'source', 'status', 'sale_type', 'price_current',
            'bids_count', 'end_time', 'model_key', 'condition_grade', 'item_type', 'key_source',
        ])->where(Source::activeListing($this->resourceConnection, 'main_table'));
        $this->getSelect()->columns(['source_colour' => Source::colourOf($this->resourceConnection, 'main_table')]);
        return $this;
    }

    protected function _renderOrders()
    {
        // Keep rows with equal sort values in a consistent order across pages.
        $this->_orders += ['id' => self::SORT_ORDER_DESC];
        return parent::_renderOrders();
    }
}
