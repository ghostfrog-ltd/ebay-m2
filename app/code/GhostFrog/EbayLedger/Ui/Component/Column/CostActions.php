<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Ui\Component\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class CostActions extends Column
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
            $dataSource['data']['items'][$index][$name] = [
                'edit' => ['href' => $this->urlBuilder->getUrl('ghostfrog_ledger/cost/edit', ['cost_id' => $item['cost_id']]),
                    'label' => __('Edit')],
                'delete' => [
                    'href' => $this->urlBuilder->getUrl('ghostfrog_ledger/cost/delete', ['cost_id' => $item['cost_id']]),
                    'label' => __('Delete'),
                    'confirm' => ['title' => __('Delete cost'), 'message' => __('Delete this cost?')],
                ],
            ];
        }
        return $dataSource;
    }
}
