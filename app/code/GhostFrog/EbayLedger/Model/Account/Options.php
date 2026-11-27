<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Account;

use GhostFrog\EbayLedger\Model\ResourceModel\Account as AccountResource;
use Magento\Framework\Data\OptionSourceInterface;

/** Accounts by label, for grid filters. */
class Options implements OptionSourceInterface
{
    public function __construct(private readonly AccountResource $resource)
    {
    }

    public function toOptionArray(): array
    {
        $db = $this->resource->getConnection();
        $rows = $db->fetchPairs($db->select()->from($this->resource->getMainTable(), ['account_id', 'label'])->order('label'));
        $options = [];
        foreach ($rows as $id => $label) {
            $options[] = ['value' => (string)$id, 'label' => $label];
        }
        return $options;
    }
}
