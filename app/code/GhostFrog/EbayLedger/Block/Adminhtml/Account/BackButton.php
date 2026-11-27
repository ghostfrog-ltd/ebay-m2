<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Block\Adminhtml\Account;

class BackButton extends Buttons
{
    public function getButtonData(): array
    {
        return ['label' => __('Back'), 'on_click' => $this->go('*/*/index'), 'class' => 'back', 'sort_order' => 10];
    }
}
