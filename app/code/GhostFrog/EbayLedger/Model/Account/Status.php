<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Account;

use GhostFrog\EbayLedger\Model\Account;
use Magento\Framework\Data\OptionSourceInterface;

class Status implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Account::STATUS_NOT_CONNECTED, 'label' => __('Not Connected')],
            ['value' => Account::STATUS_CONNECTED, 'label' => __('Connected')],
            ['value' => Account::STATUS_EXPIRED, 'label' => __('Expired - Connect Again')],
            ['value' => Account::STATUS_ERROR, 'label' => __('Error')],
        ];
    }
}
