<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class StaleAction implements OptionSourceInterface
{
    public const MARKDOWN = 'markdown';
    public const WRITE_OFF = 'write_off';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::MARKDOWN, 'label' => __('Sell at a markdown')],
            ['value' => self::WRITE_OFF, 'label' => __('Write off')],
        ];
    }
}
