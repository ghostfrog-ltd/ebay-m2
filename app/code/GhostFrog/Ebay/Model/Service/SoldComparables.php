<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Service;

use GhostFrog\Ebay\Api\SoldComparablesInterface;
use GhostFrog\Ebay\Model\ResourceModel\Listing;
use Magento\Framework\App\ResourceConnection;

/** Reads the deduplicated comparable sales that core's comps index selected. Read-only. */
final class SoldComparables implements SoldComparablesInterface
{
    private const SALES_TABLE = 'ghostfrog_ebay_comp_sales';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function sales(string $modelKey, string $grade, string $fromUtc, string $untilUtc): array
    {
        $db = $this->resource->getConnection();
        $select = $db->select()
            ->from(['c' => $this->resource->getTableName(self::SALES_TABLE)],
                ['item_id', 'listing_id', 'final_price', 'sale_type'])
            ->join(['a' => $this->resource->getTableName(Listing::TABLE)], 'a.id = c.listing_id', ['end_time'])
            ->where('c.model_key = ?', $modelKey)
            ->where('c.condition_grade = ?', $grade)
            ->where('a.end_time >= ?', $fromUtc)
            ->where('a.end_time <= ?', $untilUtc)
            ->order(['a.end_time ASC', 'c.listing_id ASC']);
        return array_map(static fn(array $row): array => [
            'item_id' => (string)$row['item_id'],
            'listing_id' => (int)$row['listing_id'],
            'final_price' => (float)$row['final_price'],
            'sale_type' => $row['sale_type'],
            'end_time' => $row['end_time'],
        ], $db->fetchAll($select));
    }
}
