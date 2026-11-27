<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Cost;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\OptionSourceInterface;

/** Stock units by reference, for the cost form. */
class UnitOptions implements OptionSourceInterface
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function toOptionArray(): array
    {
        $db = $this->resource->getConnection();
        $rows = $db->fetchAll($db->select()->from($this->resource->getTableName('ghostfrog_ebayledger_stock_unit'),
            ['stock_unit_id', 'reference', 'title'])->order('stock_unit_id DESC'));
        return array_map(static fn(array $row) => [
            'value' => (string)$row['stock_unit_id'],
            'label' => $row['reference'] . ' – ' . mb_substr((string)$row['title'], 0, 60),
        ], $rows);
    }
}
