<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Cost;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\OptionSourceInterface;

/** Ledger products by SKU, for the cost form. A cost without one belongs to the whole stock unit. */
class ProductOptions implements OptionSourceInterface
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function toOptionArray(): array
    {
        $db = $this->resource->getConnection();
        $rows = $db->fetchAll($db->select()->from($this->resource->getTableName('ghostfrog_ebayledger_product'),
            ['ledger_product_id', 'sku', 'part_name'])->where('status <> ?', 'replaced')->order('sku'));
        $options = [['value' => '', 'label' => __('The whole stock unit')]];
        foreach ($rows as $row) {
            $options[] = ['value' => (string)$row['ledger_product_id'], 'label' => $row['sku'] . ' – ' . $row['part_name']];
        }
        return $options;
    }
}
