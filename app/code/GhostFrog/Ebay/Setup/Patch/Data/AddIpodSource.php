<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Setup\Patch\Data;

use GhostFrog\Ebay\Model\ResourceModel\Source;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/** Registers the iPod source row. Its searches live in SourceSearches under the same domain. */
class AddIpodSource implements DataPatchInterface
{
    private const DOMAIN = 'ebay-ipod';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function apply(): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(Source::TABLE);
        $exists = $connection->fetchOne(
            $connection->select()->from($table, ['id'])->where('name = ? OR domain = ?', self::DOMAIN)
        );
        if ($exists !== false) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        // Starts paused so it does not draw on the eBay API quota until enabled in Sources admin.
        $connection->insert($table, [
            'name' => self::DOMAIN,
            'type' => 'ebay',
            'base_url' => 'https://www.ebay.co.uk/b/iPods-MP3-Players/73839',
            'enabled' => 0,
            'created_at_utc' => $now,
            'updated_at_utc' => $now,
            'niche' => 'ipod',
            'scrape_interval_seconds' => 3600,
            'last_scraped_at' => null,
            'domain' => self::DOMAIN,
        ]);
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
