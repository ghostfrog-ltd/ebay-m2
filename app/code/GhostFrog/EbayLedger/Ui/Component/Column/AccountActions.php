<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Ui\Component\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class AccountActions extends Column
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
            $id = $item['account_id'];
            $actions = [
                'edit' => ['href' => $this->urlBuilder->getUrl('ghostfrog_ledger/account/edit', ['account_id' => $id]),
                    'label' => __('Edit')],
                'connect' => ['href' => $this->urlBuilder->getUrl('ghostfrog_ledger/account/connect', ['account_id' => $id]),
                    'label' => $item['status'] === 'connected' ? __('Reconnect to eBay') : __('Connect to eBay')],
            ];
            if ($item['status'] === 'connected' && in_array($item['role'], ['buyer', 'both'], true)) {
                $actions['import'] = ['href' => $this->urlBuilder->getUrl('ghostfrog_ledger/account/import', ['account_id' => $id]),
                    'label' => __('Import Purchases Now')];
            }
            $dataSource['data']['items'][$index][$name] = $actions;
        }
        return $dataSource;
    }
}
