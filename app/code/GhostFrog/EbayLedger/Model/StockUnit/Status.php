<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\StockUnit;

use Magento\Framework\Data\OptionSourceInterface;

class Status implements OptionSourceInterface
{
    public const PURCHASED = 'purchased';
    public const IN_TRANSIT = 'in_transit';
    public const RECEIVED = 'received';
    public const PLANNED = 'planned';
    public const LISTED = 'listed';
    public const PARTLY_SOLD = 'partly_sold';
    public const SOLD = 'sold';
    public const WRITTEN_OFF = 'written_off';
    public const RETURNED_TO_SELLER = 'returned_to_seller';

    public const LABELS = [
        self::PURCHASED => 'Purchased',
        self::IN_TRANSIT => 'In Transit',
        self::RECEIVED => 'Received',
        self::PLANNED => 'Planned',
        self::LISTED => 'Listed',
        self::PARTLY_SOLD => 'Partly Sold',
        self::SOLD => 'Sold',
        self::WRITTEN_OFF => 'Written Off',
        self::RETURNED_TO_SELLER => 'Returned to Seller',
    ];

    public function toOptionArray(): array
    {
        $options = [];
        foreach (self::LABELS as $value => $label) {
            $options[] = ['value' => $value, 'label' => __($label)];
        }
        return $options;
    }
}
