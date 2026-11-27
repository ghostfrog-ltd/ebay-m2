<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Ui\Component\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class ProductActions extends Column
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
            $actions = ['plan' => [
                'href' => $this->urlBuilder->getUrl('ghostfrog_ledger/stockunit/plan', ['stock_unit_id' => $item['stock_unit_id']]),
                'label' => __('Sell Plan'),
            ]];
            if (!empty($item['product_id'])) {
                $actions['product'] = [
                    'href' => $this->urlBuilder->getUrl('catalog/product/edit', ['id' => $item['product_id']]),
                    'label' => __('Magento Product'),
                ];
            }
            $dataSource['data']['items'][$index][$name] = $actions;
        }
        return $dataSource;
    }
}
