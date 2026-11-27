<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\Pot;

use GhostFrog\EbayShadow\Model\Config\Settings;
use GhostFrog\EbayShadow\Model\ResourceModel\Pot\CollectionFactory;
use Magento\Ui\DataProvider\AbstractDataProvider;

class DataProvider extends AbstractDataProvider
{
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly Settings $settings,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    public function getData(): array
    {
        $data = [];
        foreach ($this->collection->getItems() as $pot) {
            $row = $pot->getData();
            $overrides = json_decode((string)($row['overrides'] ?? ''), true);
            $row['overrides'] = [];
            foreach (is_array($overrides) ? $overrides : [] as $key => $value) {
                $row['overrides'][str_replace('/', '__', $key)] = is_array($value) ? '' : (string)$value;
            }
            $data[$pot->getId()] = $row;
        }
        if ($data === []) {
            // Defaults for a new pot.
            $data[''] = [
                'starting_capital' => $this->settings->resolve()['capital/default_capital'],
                'start_date' => gmdate('Y-m-d H:i:s'),
                'status' => 'running',
            ];
        }
        return $data;
    }
}
