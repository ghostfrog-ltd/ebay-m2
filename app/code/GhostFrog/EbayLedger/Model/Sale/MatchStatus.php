<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Sale;

use Magento\Framework\Data\OptionSourceInterface;

class MatchStatus implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'unmatched', 'label' => __('Unmatched')],
            ['value' => 'matched', 'label' => __('Matched by SKU')],
            ['value' => 'linked', 'label' => __('Linked by hand')],
            ['value' => 'ignored', 'label' => __('Ignored')],
        ];
    }
}
