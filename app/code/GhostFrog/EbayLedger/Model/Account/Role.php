<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Account;

use GhostFrog\EbayLedger\Model\Account;
use Magento\Framework\Data\OptionSourceInterface;

class Role implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Account::ROLE_BUYER, 'label' => __('Buyer')],
            ['value' => Account::ROLE_SELLER, 'label' => __('Seller')],
            ['value' => Account::ROLE_BOTH, 'label' => __('Buyer and Seller')],
        ];
    }
}
