<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Product;

use Magento\Framework\Data\OptionSourceInterface;

class LineStatus implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'active', 'label' => __('For sale')],
            ['value' => 'sold', 'label' => __('Sold')],
            ['value' => 'replaced', 'label' => __('Replaced by a new plan')],
        ];
    }
}
