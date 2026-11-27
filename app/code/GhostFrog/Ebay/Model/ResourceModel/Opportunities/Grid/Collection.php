<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ResourceModel\Opportunities\Grid;

use GhostFrog\Ebay\Model\Comps\Eligibility;
use GhostFrog\Ebay\Model\Condition\Assessment;
use GhostFrog\Ebay\Model\Condition\Stage;
use GhostFrog\Ebay\Model\ModelKey\Pending;
use GhostFrog\Ebay\Model\Opportunities\ListingUrl;
use GhostFrog\Ebay\Model\ResourceModel\Listing;
use GhostFrog\Ebay\Model\ResourceModel\Source;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

class Collection extends SearchResult
{
    /** Whether this grid lists faulty opportunities (grade F) instead of working ones. */
    protected const FAULTY = false;

    /** Whether this grid lists every grade where the seller accepts Best Offers. */
    protected const BEST_OFFER = false;

    protected function _initSelect()
    {
        parent::_initSelect();
        $this->getSelect()->where('main_table.eligible_until > UTC_TIMESTAMP()');
        if (static::BEST_OFFER) {
            $this->getSelect()->where('main_table.best_offer = 1');
        } else {
            $this->getSelect()->where(
                'main_table.condition_grade ' . (static::FAULTY ? '=' : '<>') . ' ?',
                Eligibility::FAULTY_GRADE
            );
        }
        $this->getSelect()->columns(['source_colour' => new Expression(sprintf(
            '(SELECT gf_colour_source.colour FROM %s AS gf_colour_listing'
            . ' INNER JOIN %s AS gf_colour_source ON gf_colour_source.id = gf_colour_listing.source_id'
            . ' WHERE gf_colour_listing.id = main_table.listing_id)',
            $this->getConnection()->quoteIdentifier($this->getTable(Listing::TABLE)),
            $this->getConnection()->quoteIdentifier($this->getTable(Source::TABLE))
        ))]);
        // How far the price can rise before the buy stops meeting its target.
        $headroom = new Expression('main_table.max_safe_buy - main_table.current_price');
        $this->getSelect()->columns(['headroom' => $headroom]);
        $this->addFilterToMap('headroom', $headroom);
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
        // Keyed at collection and still waiting for item details and condition checks to confirm the key.
        $pending = new Expression(sprintf(
            '(SELECT %s FROM %s AS gf_pending WHERE gf_pending.id = main_table.listing_id)',
            Pending::sql('gf_pending'),
            $this->getConnection()->quoteIdentifier($this->getTable(Listing::TABLE))
        ));
        $this->getSelect()->columns(['checks_pending' => $pending]);
        $this->addFilterToMap('checks_pending', $pending);
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
        if (is_numeric($item->getData('sold_median'))) {
            $item->setData('sold_median', number_format((float)$item->getData('sold_median'), 2, '.', ''));
        }
        return parent::beforeAddLoadedItem($item);
    }
}
