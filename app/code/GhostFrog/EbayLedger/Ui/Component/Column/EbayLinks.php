<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Ui\Component\Column;

use Magento\Ui\Component\Listing\Columns\Column;

/** Opens the item or your purchase details on eBay UK in a new tab. */
class EbayLinks extends Column
{
    public function prepareDataSource(array $dataSource): array
    {
        $name = $this->getData('name');
        foreach ($dataSource['data']['items'] ?? [] as $index => $item) {
            $itemId = rawurlencode((string)($item['ebay_item_id'] ?? ''));
            $transactionId = rawurlencode((string)($item['ebay_transaction_id'] ?? ''));
            $links = [
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
