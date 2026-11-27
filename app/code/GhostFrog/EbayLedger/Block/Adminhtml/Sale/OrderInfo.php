<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Block\Adminhtml\Sale;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ResourceConnection;

/** The eBay order and line IDs behind a Magento order, on the order's admin page. */
class OrderInfo extends Template
{
    public function __construct(Context $context, private readonly ResourceConnection $resource, array $data = [])
    {
        parent::__construct($context, $data);
    }

    public function getLines(): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchAll($db->select()->from(['l' => $this->resource->getTableName('ghostfrog_ebayledger_sale_line')],
            ['ebay_order_id', 'ebay_line_item_id', 'ebay_item_id', 'sku', 'line_price', 'postage', 'refunded', 'fulfillment_status'])
            ->where('l.magento_order_id = ?', (int)$this->getRequest()->getParam('order_id')));
    }
}
