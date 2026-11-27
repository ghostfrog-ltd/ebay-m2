<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Config\Source;

use GhostFrog\Ebay\Model\ModelKey\Rules\RcCars;
use Magento\Framework\Data\OptionSourceInterface;

class RcItemTypes implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        $options = [];
        foreach (RcCars::TYPES as $value => $label) {
            $options[] = ['value' => $value, 'label' => __($label)];
        }
        return $options;
    }
}
