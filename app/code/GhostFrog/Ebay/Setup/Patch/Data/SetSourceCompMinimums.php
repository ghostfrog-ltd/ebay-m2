<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Setup\Patch\Data;

use GhostFrog\Ebay\Model\ResourceModel\Source;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Moves the sold-comp minimums that were hard-coded in Valuation onto their sources, where they are
 * editable. Every other source keeps the column default of 15.
 */
class SetSourceCompMinimums implements DataPatchInterface
{
    private const MINIMUMS = [
        'ebay-apple' => 10,
        'ebay-ipod' => 5,
        'ebay-rc' => 5,
        'ebay-samsung' => 5,
        'ebay-graphics-cards' => 5,
    ];

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function apply(): void
    {
        $connection = $this->resource->getConnection();
        foreach (self::MINIMUMS as $domain => $minimum) {
            $connection->update(
                $this->resource->getTableName(Source::TABLE),
                ['min_comps' => $minimum],
                ['domain = ?' => $domain]
            );
        }
    }

    public static function getDependencies(): array
    {
        return [AddIpodSource::class, AddRcSource::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
