<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Ui\Component\Opportunities\Column;

use Magento\Framework\Data\OptionSourceInterface;

class SaleTypes implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'auction', 'label' => __('Auction')],
            ['value' => 'bin', 'label' => __('Buy It Now')],
            ['value' => 'both', 'label' => __('Auction and Buy It Now')],
        ];
    }
}
