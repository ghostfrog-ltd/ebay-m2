<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ResourceModel\Accessories\Grid;

use GhostFrog\Ebay\Model\ModelKey\Rules\ConsoleAccessories;
use GhostFrog\Ebay\Model\Opportunities\ListingUrl;
use GhostFrog\Ebay\Model\ResourceModel\Source;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface as FetchStrategy;
use Magento\Framework\Data\Collection\EntityFactoryInterface as EntityFactory;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface as Logger;

/**
 * Live accessory listings with what the same kind of accessory sells for, so the parts a console-only buy is
 * missing can be found cheaply. Accessories rarely clear the source's profit target, so they are listed here
 * whether or not they would be an opportunity.
 */
class Collection extends SearchResult
{
    private const MIN_COMPS = 5;

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
        $db = $this->resourceConnection->getConnection();
        $platform = [];
        $kind = [];
        foreach (ConsoleAccessories::platforms() as $name) {
            $prefix = ConsoleAccessories::PREFIX . $name . '-';
            $like = $db->quoteInto('main_table.model_key LIKE ?', $prefix . '%');
            $platform[] = "WHEN $like THEN " . $db->quote($name);
            $kind[] = "WHEN $like THEN SUBSTRING(main_table.model_key, " . (strlen($prefix) + 1) . ')';
        }
        $platform = new Expression('CASE ' . implode(' ', $platform) . ' END');
        $kind = new Expression('CASE ' . implode(' ', $kind) . ' END');
        // A Buy It Now price below the current bid is the price to pay.
        $price = new Expression('IF(main_table.price_bin_current > 0 AND main_table.price_bin_current'
            . ' < main_table.price_current, main_table.price_bin_current, main_table.price_current)');
        // A median from one or two sales is noise: it is shown only once enough sales stand behind it.
        $enough = 'c.samples >= ' . self::MIN_COMPS;
        $median = new Expression("IF($enough, ROUND(c.median_final_price, 2), NULL)");
        $saving = new Expression("IF($enough, ROUND(c.median_final_price - $price, 2), NULL)");

        $this->getSelect()
            ->from(['main_table' => $this->getMainTable()], [
                'id', 'external_id', 'url', 'title', 'model_key', 'condition_grade', 'sale_type', 'end_time',
            ])
            ->columns([
                'platform' => $platform,
                'kind' => $kind,
                'price' => $price,
                'sold_median' => $median,
                'comp_count' => 'c.samples',
                'saving' => $saving,
            ])
            ->joinLeft(
                ['c' => $this->getTable('ghostfrog_ebay_comps')],
                "c.model_key = main_table.model_key AND c.condition_grade ="
                . " IF(main_table.condition_grade IN ('A', 'B', 'C'), main_table.condition_grade, 'B')",
                []
            )
            ->where('main_table.status = ?', 'live')
            ->where('main_table.model_key LIKE ?', ConsoleAccessories::PREFIX . '%')
            ->where('main_table.price_current > 0')
            ->where("main_table.end_time > UTC_TIMESTAMP() OR (main_table.end_time IS NULL AND main_table.sale_type = 'bin')")
            ->where(Source::activeListing($this->resourceConnection, 'main_table'));

        foreach ([
            'platform' => $platform, 'kind' => $kind, 'price' => $price, 'sold_median' => $median,
            'saving' => $saving, 'comp_count' => 'c.samples',
        ] as $field => $expression) {
            $this->addFilterToMap($field, $expression);
        }
        foreach (['id', 'title', 'model_key', 'condition_grade', 'sale_type', 'end_time'] as $field) {
            $this->addFilterToMap($field, 'main_table.' . $field);
        }
        return $this;
    }

    protected function _renderOrders()
    {
        if ($this->_orders === []) {
            $this->_orders = ['saving' => self::SORT_ORDER_DESC];
        }
        $this->_orders += ['id' => self::SORT_ORDER_DESC];
        return parent::_renderOrders();
    }

    protected function beforeAddLoadedItem(DataObject $item): DataObject
    {
        $item->setData('ebay_listing_url', ListingUrl::resolve(
            $item->getData('url'),
            (string)$item->getData('external_id')
        ));
        return parent::beforeAddLoadedItem($item);
    }
}
