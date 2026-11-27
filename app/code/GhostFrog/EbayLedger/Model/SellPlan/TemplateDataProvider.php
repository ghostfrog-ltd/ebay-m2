<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\SellPlan;

use GhostFrog\EbayLedger\Model\ResourceModel\PartsTemplate\CollectionFactory;
use Magento\Ui\DataProvider\AbstractDataProvider;

class TemplateDataProvider extends AbstractDataProvider
{
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    public function getData(): array
    {
        $data = [];
        foreach ($this->collection->getItems() as $template) {
            $row = Templates::decode($template->getData());
            foreach ($row['parts'] as $index => $part) {
                $row['parts'][$index] = ['record_id' => $index] + $part + ['estimate' => ''];
                $row['parts'][$index]['estimate'] = $part['estimate'] === null ? '' : (string)$part['estimate'];
            }
            $data[$template->getId()] = $row;
        }
        if ($data === []) {
            $data[''] = ['active' => 1, 'whole_size_band' => 'large', 'parts' => []];
        }
        return $data;
    }
}
