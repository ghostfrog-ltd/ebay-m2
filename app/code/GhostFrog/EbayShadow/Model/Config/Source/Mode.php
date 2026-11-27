<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\Config\Source;

use GhostFrog\EbayShadow\Model\Config\Settings;
use Magento\Framework\Data\OptionSourceInterface;

/** Off or shadow. Live is deliberately not an option in this build. */
class Mode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Settings::MODE_OFF, 'label' => __('Off')],
            ['value' => Settings::MODE_SHADOW, 'label' => __('Shadow (simulated, never buys)')],
        ];
    }
}
