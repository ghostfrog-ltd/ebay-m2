<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Ui\Component\Opportunities\Column;

use GhostFrog\Ebay\Model\Opportunities\Rows;
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
        ));
        $codes = array_map(static fn (array $source): string => $source['domain'] ?: $source['name'], $registered);

        // Keep older source values filterable while they are present in the index.
        $indexed = $db->fetchCol($db->select()->distinct()
            ->from($this->resource->getTableName(Rows::TABLE), 'source'));
        $codes = array_unique(array_merge($codes, $indexed));
        $codes = array_filter($codes, static fn (string $code): bool => $code !== '');
        natcasesort($codes);

        return array_values(array_map(static fn (string $code): array => ['value' => $code, 'label' => $code], $codes));
    }
}
