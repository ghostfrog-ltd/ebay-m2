<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ResourceModel\Opportunities\Grid;

use GhostFrog\Ebay\Model\Condition\Assessment;
use GhostFrog\Ebay\Model\Condition\Stage;
use GhostFrog\Ebay\Model\Opportunities\ListingUrl;
use Magento\Framework\DataObject;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

class Collection extends SearchResult
{
    protected function _initSelect()
    {
        parent::_initSelect();
        $this->getSelect()->where('main_table.eligible_until > UTC_TIMESTAMP()');
        foreach (Stage::cases() as $stage) {
            $field = 'llm_graded_' . $stage->value;
            $graded = Assessment::modelResultExists(
                $this->getConnection(),
                $this->getTable($stage->assessmentTable()),
                $stage,
                'main_table.listing_id'
            );
            $this->getSelect()->columns([$field => $graded]);
            $this->addFilterToMap($field, $graded);
        }
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

    protected function beforeAddLoadedItem(DataObject $item): DataObject
    {
        $item->setData('ebay_listing_url', ListingUrl::resolve(
            $item->getData('url'),
            (string)$item->getData('external_id')
        ));
        return parent::beforeAddLoadedItem($item);
    }
}
