<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Statistic implements OptionSourceInterface
{
    public const MEDIAN = 'median';
    public const LOWER_QUARTILE = 'lower_quartile';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::LOWER_QUARTILE, 'label' => __('Lower quartile')],
            ['value' => self::MEDIAN, 'label' => __('Median')],
        ];
    }
}
