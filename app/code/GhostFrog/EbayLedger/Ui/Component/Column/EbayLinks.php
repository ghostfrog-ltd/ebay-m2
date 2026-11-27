<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Ui\Component\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/** Receive and Inspect, and the item or your purchase details on eBay UK in a new tab. */
class EbayLinks extends Column
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
            $itemId = rawurlencode((string)($item['ebay_item_id'] ?? ''));
            $transactionId = rawurlencode((string)($item['ebay_transaction_id'] ?? ''));
            $links = [
                'edit' => [
                    'href' => $this->urlBuilder->getUrl('ghostfrog_ledger/stockunit/edit', ['stock_unit_id' => $item['stock_unit_id']]),
                    'label' => in_array($item['status'] ?? '', ['purchased', 'in_transit'], true) ? __('Receive') : __('Edit'),
                ],
                'plan' => [
                    'href' => $this->urlBuilder->getUrl('ghostfrog_ledger/stockunit/plan', ['stock_unit_id' => $item['stock_unit_id']]),
                    'label' => __('Sell Plan'),
                ],
                'item' => ['href' => 'https://www.ebay.co.uk/itm/' . $itemId, 'label' => __('eBay Item'), 'target' => '_blank'],
            ];
            if ($transactionId !== '') {
                $links['order'] = [
                    'href' => "https://www.ebay.co.uk/vod/FetchOrderDetails?itemid=$itemId&transid=$transactionId",
                    'label' => __('eBay Order'),
                    'target' => '_blank',
                ];
            }
            $dataSource['data']['items'][$index][$name] = $links;
        }
        return $dataSource;
    }
}
