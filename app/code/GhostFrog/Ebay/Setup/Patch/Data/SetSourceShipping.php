<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Setup\Patch\Data;

use GhostFrog\Ebay\Model\ResourceModel\Source;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Starting estimates for inbound plus outbound postage on a typical item from each source, so bulky
 * consoles and tools stop sharing the £12 global with cards and CPUs. Vehicle sources carry a rough
 * collection cost instead. All are editable on the source, and any source not listed uses the global.
 */
class SetSourceShipping implements DataPatchInterface
{
    private const SHIPPING = [
        'ebay-consoles' => 16,
        'ebay-retro-pc' => 22,
        'ebay-actioncams' => 8,
        'motomine' => 15,
        'ebay-watches' => 10,
        'ebay-apple' => 12,
        'ebay-tools' => 18,
        'ebay-motors' => 100,
        'ebay-lego' => 14,
        'ebay-pokemon' => 4,
        'ebay-samsung' => 10,
        'ebay-headphones' => 9,
        'ebay-honda-nc750' => 60,
        'ebay-mini-cooper' => 100,
        'ebay-honda-msx125' => 60,
        'ebay-graphics-cards' => 14,
        'ebay-cpus' => 7,
        'ebay-motherboards' => 10,
        'ebay-camera-lenses' => 12,
        'ebay-industrial-automation' => 18,
        'ebay-ipod' => 7,
        'ebay-rc' => 18,
    ];

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function apply(): void
    {
        $connection = $this->resource->getConnection();
        foreach (self::SHIPPING as $domain => $shipping) {
            $connection->update(
                $this->resource->getTableName(Source::TABLE),
                ['shipping' => $shipping],
                ['domain = ?' => $domain, 'shipping IS NULL']
            );
        }
    }

    public static function getDependencies(): array
    {
        return [SetSourceCompMinimums::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
