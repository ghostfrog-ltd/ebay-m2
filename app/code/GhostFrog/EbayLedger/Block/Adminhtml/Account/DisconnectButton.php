<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Block\Adminhtml\Account;

class DisconnectButton extends Buttons
{
    public function getButtonData(): array
    {
        if (!$this->accountId()) {
            return [];
        }
        return [
            'label' => __('Disconnect'),
            'on_click' => sprintf("confirm('%s') && (%s)",
                __('Forget this account\'s eBay sign-in? Its stock units stay.'),
                rtrim($this->go('*/*/disconnect', ['account_id' => $this->accountId()]), ';')),
            'sort_order' => 20,
        ];
    }
}
