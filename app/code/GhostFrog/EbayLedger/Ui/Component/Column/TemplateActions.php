<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Ui\Component\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class TemplateActions extends Column
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
            $parts = json_decode((string)($item['parts'] ?? ''), true);
            $dataSource['data']['items'][$index]['parts'] = implode(', ', array_column(is_array($parts) ? $parts : [], 'name'));
            $dataSource['data']['items'][$index][$name] = ['edit' => [
                'href' => $this->urlBuilder->getUrl('ghostfrog_ledger/template/edit', ['template_id' => $item['template_id']]),
                'label' => __('Edit'),
            ]];
        }
        return $dataSource;
    }
}
