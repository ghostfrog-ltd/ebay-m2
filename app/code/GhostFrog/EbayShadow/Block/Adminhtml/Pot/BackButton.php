<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Block\Adminhtml\Pot;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class BackButton implements ButtonProviderInterface
{
    public function __construct(private readonly UrlInterface $url)
    {
    }

    public function getButtonData(): array
    {
        return [
            'label' => __('Back'),
            'on_click' => sprintf("location.href = '%s';", $this->url->getUrl('*/*/index')),
            'class' => 'back',
            'sort_order' => 10,
        ];
    }
}
