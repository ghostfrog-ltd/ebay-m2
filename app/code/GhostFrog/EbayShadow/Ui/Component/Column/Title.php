<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Ui\Component\Column;

use GhostFrog\Ebay\Model\Opportunities\ListingUrl;
use Magento\Ui\Component\Listing\Columns\Column;

/** Listing title as a link to the eBay item. */
class Title extends Column
{
    public function prepareDataSource(array $dataSource): array
    {
        $name = $this->getData('name');
        foreach ($dataSource['data']['items'] ?? [] as $index => $item) {
            $title = htmlspecialchars((string)($item[$name] ?? ''), ENT_QUOTES);
            $url = ListingUrl::resolve($item['url'] ?? null, (string)($item['external_id'] ?? ''));
            $dataSource['data']['items'][$index][$name] = $url === null ? $title
                : sprintf('<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                    htmlspecialchars($url, ENT_QUOTES), $title);
        }
        return $dataSource;
    }
}
