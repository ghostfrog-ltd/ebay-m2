<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Cost;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\RequestInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;

class DataProvider extends AbstractDataProvider
{
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        private readonly ResourceConnection $resource,
        private readonly RequestInterface $request,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    public function getData(): array
    {
        $id = (int)$this->request->getParam('cost_id');
        if ($id > 0) {
            $db = $this->resource->getConnection();
            $row = $db->fetchRow($db->select()->from($this->resource->getTableName(Costs::TABLE))->where('cost_id = ?', $id));
            if ($row) {
                return [$id => $row];
            }
        }
        return ['' => [
            'stock_unit_id' => (string)$this->request->getParam('stock_unit_id'),
            'incurred_on' => gmdate('Y-m-d'),
            'cost_type' => 'packaging',
        ]];
    }

    public function addFilter(\Magento\Framework\Api\Filter $filter)
    {
        return null;
    }
}
