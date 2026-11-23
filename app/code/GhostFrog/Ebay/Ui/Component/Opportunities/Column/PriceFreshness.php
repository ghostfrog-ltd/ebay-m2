<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Ui\Component\Opportunities\Column;

use GhostFrog\Ebay\Model\PriceRefresh\Settings;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

final class PriceFreshness extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly Settings $settings,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        $now = time();
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as &$item) {
                $end = Settings::timestamp($item['end_time']);
                $remaining = $end === null ? null : $end - $now;
                $interval = $this->settings->interval((string)$item['sale_type'], $remaining);
                $item[$this->getData('name')] = match (true) {
                    $remaining !== null && $remaining <= 0 => __('Expired'),
                    $item['last_price_checked_at'] === null => __('Unverified'),
                    $this->settings->isFresh($item, $now, $interval) => __('Fresh'),
                    default => __('Stale'),
                };
            }
        }
        return $dataSource;
    }
}
