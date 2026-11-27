<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Account;

use GhostFrog\EbayLedger\Model\Account;
use GhostFrog\EbayLedger\Model\ResourceModel\Account\CollectionFactory;
use Magento\Ui\DataProvider\AbstractDataProvider;

/** Account form data. Tokens never reach the browser. */
class DataProvider extends AbstractDataProvider
{
    private const FIELDS = [
        'account_id', 'label', 'role', 'enabled', 'status', 'ebay_username', 'access_expires_at',
        'refresh_expires_at', 'connected_at', 'last_error', 'purchases_imported_at',
    ];

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
        foreach ($this->collection->getItems() as $account) {
            $row = array_intersect_key($account->getData(), array_flip(self::FIELDS));
            $row['status'] = match ($row['status'] ?? null) {
                Account::STATUS_CONNECTED => __('Connected')->render(),
                Account::STATUS_EXPIRED => __('Expired - connect again')->render(),
                Account::STATUS_ERROR => __('Error')->render(),
                default => __('Not connected')->render(),
            };
            foreach (['access_expires_at', 'refresh_expires_at', 'connected_at', 'purchases_imported_at'] as $field) {
                $row[$field] = $row[$field] ? $row[$field] . ' UTC' : '—';
            }
            $data[$account->getId()] = $row;
        }
        if ($data === []) {
            $data[''] = ['role' => Account::ROLE_BOTH, 'enabled' => 1];
        }
        return $data;
    }
}
