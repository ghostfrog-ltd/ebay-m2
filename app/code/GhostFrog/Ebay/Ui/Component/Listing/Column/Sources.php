<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Ui\Component\Listing\Column;

use GhostFrog\Ebay\Model\ResourceModel\Source;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\OptionSourceInterface;

class Sources implements OptionSourceInterface
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function toOptionArray(): array
    {
        $db = $this->resource->getConnection();
        $registered = $db->fetchAll($db->select()->from(
            $this->resource->getTableName('ghostfrog_ebay_sources'),
            ['domain', 'name']
        )->where('COALESCE(enabled, 0) = 1'));
        $codes = array_map(static fn (array $source): string => (string) ($source['domain'] ?: $source['name']), $registered);

        // Older source values stay filterable while listings from enabled sources still carry them.
        $stored = $db->fetchCol($db->select()->distinct()
            ->from(['a' => $this->resource->getTableName('ghostfrog_ebay_auction_listings')], 'source')
            ->where(Source::activeListing($this->resource, 'a')));
        $codes = array_unique(array_merge($codes, $stored));
        $codes = array_filter($codes, static fn (string $code): bool => $code !== '');
        natcasesort($codes);

        return array_values(array_map(static fn (string $code): array => ['value' => $code, 'label' => $code], $codes));
    }
}
