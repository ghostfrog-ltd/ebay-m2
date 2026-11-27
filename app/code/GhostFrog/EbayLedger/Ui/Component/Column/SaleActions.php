<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Ui\Component\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class SaleActions extends Column
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
            $actions = [];
            if (empty($item['magento_order_id'])) {
                $actions['link'] = [
                    'href' => $this->urlBuilder->getUrl('ghostfrog_ledger/sale/link', ['sale_line_id' => $item['sale_line_id']]),
                    'label' => $item['match_status'] === 'ignored' ? __('Review') : __('Link or Ignore'),
                ];
            } else {
                $actions['order'] = [
                    'href' => $this->urlBuilder->getUrl('sales/order/view', ['order_id' => $item['magento_order_id']]),
                    'label' => __('Magento Order'),
                ];
            }
            if (!empty($item['ebay_item_id'])) {
                $actions['item'] = ['href' => 'https://www.ebay.co.uk/itm/' . rawurlencode((string)$item['ebay_item_id']),
                    'label' => __('eBay Item'), 'target' => '_blank'];
            }
            $dataSource['data']['items'][$index][$name] = $actions;
        }
        return $dataSource;
    }
}
