<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\StockUnit;

use Magento\Framework\Data\OptionSourceInterface;

/** Core's condition grades, so predicted and actual grades compare directly. */
class Grade implements OptionSourceInterface
{
    public const LABELS = [
        'A' => 'A - Like new',
        'B' => 'B - Good, light wear',
        'C' => 'C - Fair, heavy wear',
        'D' => 'D - Poor',
        'F' => 'F - Faulty or for parts',
    ];

    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => __('Not graded yet')]];
        foreach (self::LABELS as $value => $label) {
            $options[] = ['value' => $value, 'label' => __($label)];
        }
        return $options;
    }
}
