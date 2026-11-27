<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Block\Adminhtml\Sale;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ResourceConnection;

class Link extends Template
{
    public function __construct(Context $context, private readonly ResourceConnection $resource, array $data = [])
    {
        parent::__construct($context, $data);
    }

    public function getLine(): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->resource->getTableName('ghostfrog_ebayledger_sale_line'))
            ->where('sale_line_id = ?', (int)$this->getRequest()->getParam('sale_line_id')));
        return $row ?: null;
    }

    /** Products still for sale and not already matched to a sale. */
    public function getProducts(): array
    {
        $db = $this->resource->getConnection();
        $claimed = $db->select()->from(['s' => $this->resource->getTableName('ghostfrog_ebayledger_sale_line')], ['ledger_product_id'])
            ->where('s.match_status IN (?)', ['matched', 'linked'])->where('s.ledger_product_id IS NOT NULL');
        return $db->fetchAll($db->select()->from(['p' => $this->resource->getTableName('ghostfrog_ebayledger_product')],
            ['ledger_product_id', 'sku', 'part_name', 'planned_price'])
            ->join(['u' => $this->resource->getTableName('ghostfrog_ebayledger_stock_unit')], 'u.stock_unit_id = p.stock_unit_id', ['title'])
            ->where('p.status = ?', 'active')->where('p.ledger_product_id NOT IN (?)', $claimed)->order('p.sku'));
    }
}
