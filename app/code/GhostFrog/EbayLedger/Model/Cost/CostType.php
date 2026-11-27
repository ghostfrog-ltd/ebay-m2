<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Cost;

use Magento\Framework\Data\OptionSourceInterface;

class CostType implements OptionSourceInterface
{
    public const LABELS = [
        'packaging' => 'Packaging',
        'postage' => 'Postage I paid',
        'repair' => 'Repair or parts',
        'other' => 'Other',
    ];

    public function toOptionArray(): array
    {
        $options = [];
        foreach (self::LABELS as $value => $label) {
            $options[] = ['value' => $value, 'label' => __($label)];
        }
        return $options;
    }
}
