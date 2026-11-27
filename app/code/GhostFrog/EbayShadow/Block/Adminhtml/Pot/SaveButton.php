<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Block\Adminhtml\Pot;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class SaveButton implements ButtonProviderInterface
{
    public function __construct(private readonly UrlInterface $url)
    {
    }

    public function getButtonData(): array
    {
        return [
            'label' => __('Save Pot'),
            'class' => 'save primary',
            'data_attribute' => [
                'mage-init' => ['button' => ['event' => 'save']],
                'form-role' => 'save',
            ],
            'sort_order' => 90,
        ];
    }
}
