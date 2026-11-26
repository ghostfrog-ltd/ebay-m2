<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Ui\Component\Listing\Column;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\OptionSourceInterface;

class Statuses implements OptionSourceInterface
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function toOptionArray(): array
    {
        $db = $this->resource->getConnection();
        $statuses = $db->fetchCol($db->select()
            ->from($this->resource->getTableName('ghostfrog_ebay_listing_statuses'), 'status')
            ->order('status'));

        return array_map(static fn (string $status): array => ['value' => $status, 'label' => $status], $statuses);
    }
}
