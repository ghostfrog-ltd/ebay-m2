<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\StockUnit;

use Magento\Framework\Data\OptionSourceInterface;

/** What happens to a unit after it arrives. */
class Outcome implements OptionSourceInterface
{
    public const KEEP = 'keep';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::KEEP, 'label' => __('Keeping it to sell')],
            ['value' => Status::RETURNED_TO_SELLER, 'label' => __('Returned to the seller')],
            ['value' => Status::WRITTEN_OFF, 'label' => __('Written off')],
        ];
    }
}
