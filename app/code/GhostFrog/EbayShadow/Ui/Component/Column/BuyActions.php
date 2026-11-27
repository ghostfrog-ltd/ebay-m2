<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Ui\Component\Column;

use GhostFrog\Ebay\Model\Opportunities\ListingUrl;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class BuyActions extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        $name = $this->getData('name');
        foreach ($dataSource['data']['items'] ?? [] as $index => $item) {
            $actions = [
                'view' => [
                    'href' => $this->urlBuilder->getUrl('ghostfrog_shadow/buys/view', ['id' => $item['decision_id']]),
                    'label' => __('View Evidence'),
                ],
            ];
            $url = ListingUrl::resolve($item['url'] ?? null, (string)($item['external_id'] ?? ''));
            if ($url !== null) {
                $actions['ebay'] = ['href' => $url, 'label' => __('View on eBay'), 'target' => '_blank'];
            }
            $dataSource['data']['items'][$index][$name] = $actions;
        }
        return $dataSource;
    }
}
