<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Api;

use Magento\Framework\Api\Filter;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProviderInterface;

/**
 * Grid data for API Usage: a handful of rows built from the live quota state, not a table.
 */
class UsageDataProvider implements DataProviderInterface
{
    private array $order = [];

    public function __construct(
        private readonly UsageRows $rows,
        private readonly string $name,
        private readonly string $primaryFieldName,
        private readonly string $requestFieldName,
        private array $meta = [],
        private array $data = []
    ) {
    }

    public function getData(): array
    {
        $items = $this->rows->rows();
        if ($this->order !== []) {
            [$field, $direction] = $this->order;
            usort($items, static fn (array $a, array $b): int => ($a[$field] ?? null) <=> ($b[$field] ?? null));
            if (strtolower($direction) === 'desc') {
                $items = array_reverse($items);
            }
        }
        return ['totalRecords' => count($items), 'items' => $items];
    }

    public function addOrder($field, $direction): void
    {
        $this->order = [(string)$field, (string)$direction];
    }

    public function addFilter(Filter $filter): void
    {
    }

    public function setLimit($offset, $size): void
    {
    }

    public function getName()
    {
        return $this->name;
    }

    public function getConfigData()
    {
        return $this->data['config'] ?? [];
    }

    public function setConfigData($config)
    {
        $this->data['config'] = $config;
        return true;
    }

    public function getMeta()
    {
        return $this->meta;
    }

    public function getFieldMetaInfo($fieldSetName, $fieldName)
    {
        return $this->meta[$fieldSetName]['children'][$fieldName] ?? [];
    }

    public function getFieldSetMetaInfo($fieldSetName)
    {
        return $this->meta[$fieldSetName] ?? [];
    }

    public function getFieldsMetaInfo($fieldSetName)
    {
        return $this->meta[$fieldSetName]['children'] ?? [];
    }

    public function getPrimaryFieldName()
    {
        return $this->primaryFieldName;
    }

    public function getRequestFieldName()
    {
        return $this->requestFieldName;
    }

    public function getSearchCriteria()
    {
        return null;
    }

    public function getSearchResult()
    {
        return null;
    }
}
