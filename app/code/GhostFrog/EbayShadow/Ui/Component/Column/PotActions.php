<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Ui\Component\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class PotActions extends Column
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
                'edit' => ['href' => $this->urlBuilder->getUrl('ghostfrog_shadow/pots/edit', ['pot_id' => $item['pot_id']]),
                    'label' => __('Edit')],
                'dashboard' => ['href' => $this->urlBuilder->getUrl('ghostfrog_shadow/dashboard/index', ['pot_id' => $item['pot_id']]),
                    'label' => __('Dashboard')],
            ];
        }
        return $dataSource;
    }
}
