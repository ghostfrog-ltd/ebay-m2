<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Block\Adminhtml\Account;

class SaveButton extends Buttons
{
    public function getButtonData(): array
    {
        return [
            'label' => __('Save'),
            'class' => 'save',
            'data_attribute' => ['mage-init' => ['button' => ['event' => 'save']], 'form-role' => 'save'],
            'sort_order' => 80,
        ];
    }
}
