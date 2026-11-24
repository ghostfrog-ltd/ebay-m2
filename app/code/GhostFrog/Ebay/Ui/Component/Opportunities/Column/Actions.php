<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Ui\Component\Opportunities\Column;

use GhostFrog\Ebay\Controller\Adminhtml\Listing\Index;
use GhostFrog\Ebay\Model\Opportunities\ListingUrl;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class Actions extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        private readonly AuthorizationInterface $authorization,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        $name = $this->getData('name');
        $canEdit = $this->authorization->isAllowed(Index::ADMIN_RESOURCE);
        foreach ($dataSource['data']['items'] ?? [] as $index => $item) {
            $actions = [];
            if ($canEdit) {
                $actions['edit'] = [
                    'href' => $this->urlBuilder->getUrl('ghostfrog_ebay/listing/edit', ['id' => $item['listing_id']]),
                    'label' => __('Edit Listing'),
                ];
            }
            $url = ListingUrl::resolve($item['url'] ?? null, (string)$item['external_id']);
            if ($url !== null) {
                $actions['ebay'] = ['href' => $url, 'label' => __('View on eBay'), 'target' => '_blank'];
            }
            $dataSource['data']['items'][$index][$name] = $actions;
        }
        return $dataSource;
    }
}
