<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class PotStatus implements OptionSourceInterface
{
    public const RUNNING = 'running';
    public const PAUSED = 'paused';
    public const CLOSED = 'closed';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::RUNNING, 'label' => __('Running')],
            ['value' => self::PAUSED, 'label' => __('Paused')],
            ['value' => self::CLOSED, 'label' => __('Closed')],
        ];
    }
}
