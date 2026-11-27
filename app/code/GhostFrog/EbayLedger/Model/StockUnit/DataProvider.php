<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\StockUnit;

use GhostFrog\EbayLedger\Model\ResourceModel\StockUnit\CollectionFactory;
use Magento\Ui\DataProvider\AbstractDataProvider;

/** The Receive & Inspect form. Raw eBay payloads stay on the server. */
class DataProvider extends AbstractDataProvider
{
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly Photos $photos,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    public function getData(): array
    {
        $data = [];
        foreach ($this->collection->getItems() as $unit) {
            $row = $unit->getData();
            unset($row['raw_payload'], $row['prediction']);
            $contents = json_decode((string)($row['contents'] ?? ''), true);
            $row['contents'] = is_array($contents) ? implode("\n", $contents) : '';
            $row['photos'] = $this->photos->forForm($row['photos'] ?? null);
            $row['outcome'] = in_array($row['status'], [Status::RETURNED_TO_SELLER, Status::WRITTEN_OFF], true)
                ? $row['status'] : Outcome::KEEP;
            $row['status_label'] = (string)__(Status::LABELS[$row['status']] ?? $row['status']);
            $row['cost_summary'] = sprintf('£%.2f item + £%.2f postage + £%.2f other = £%.2f', $row['item_price'],
                $row['postage_paid'], $row['other_costs'], $row['total_cost']);
            $row['prediction_summary'] = $row['predicted_grade'] === null && $row['predicted_resale'] === null
                ? (string)__('GhostFrog did not collect this listing.')
                : sprintf('Grade %s, resale about £%s from %d sold (%s)', $row['predicted_grade'] ?? '?',
                    $row['predicted_resale'] === null ? '?' : number_format((float)$row['predicted_resale'], 2),
                    (int)$row['predicted_comp_count'], $row['model_key'] ?? 'unknown model');
            $data[$unit->getId()] = $row;
        }
        return $data;
    }
}
