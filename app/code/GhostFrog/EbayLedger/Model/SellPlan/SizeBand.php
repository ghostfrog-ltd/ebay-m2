<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\SellPlan;

use GhostFrog\EbayLedger\Model\Config\Settings;
use Magento\Framework\Data\OptionSourceInterface;

class SizeBand implements OptionSourceInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->settings->postage() as $band => $price) {
            $options[] = ['value' => $band, 'label' => sprintf('%s (£%.2f postage)', ucfirst($band), $price)];
        }
        return $options;
    }
}
