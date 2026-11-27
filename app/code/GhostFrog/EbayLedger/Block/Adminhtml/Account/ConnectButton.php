<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Block\Adminhtml\Account;

class ConnectButton extends Buttons
{
    public function getButtonData(): array
    {
        if (!$this->accountId()) {
            return [];
        }
        return [
            'label' => __('Connect to eBay'),
            'class' => 'primary',
            'on_click' => $this->go('*/*/connect', ['account_id' => $this->accountId()]),
            'sort_order' => 90,
        ];
    }
}
