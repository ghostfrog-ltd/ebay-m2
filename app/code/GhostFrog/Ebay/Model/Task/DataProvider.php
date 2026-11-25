<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Task;

use GhostFrog\Ebay\Model\ResourceModel\Task\CollectionFactory;
use Magento\Ui\DataProvider\AbstractDataProvider;

class DataProvider extends AbstractDataProvider
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
        foreach ($this->collection->getItems() as $task) {
            $data[$task->getId()] = $task->getData();
        }
        return $data;
    }
}
