<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Block\Adminhtml\Account;

class ImportButton extends Buttons
{
    public function getButtonData(): array
    {
        if (!$this->accountId()) {
            return [];
        }
        return [
            'label' => __('Import Purchases Now'),
            'on_click' => $this->go('*/*/import', ['account_id' => $this->accountId(), 'back' => 'account']),
            'sort_order' => 60,
        ];
    }
}
