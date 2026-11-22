<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Block\Adminhtml\System\Config\Field;

use GhostFrog\Ebay\Model\Api\Usage;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class ApiQuota extends Field
{
    public function __construct(Context $context, private readonly Usage $usage, array $data = [])
    {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $bucket = str_ends_with($element->getHtmlId(), '_trading_daily_quota') ? Usage::TRADING : Usage::BROWSE;
        $state = $this->usage->quota($bucket);
        if ($state['limit'] === null) {
            return $this->escapeHtml(__('Waiting for a current quota from eBay.'));
        }

        $html = '<strong>' . $this->escapeHtml(__('%1 calls per day', number_format($state['limit']))) . '</strong>';
        $html .= '<p class="note">' . $this->escapeHtml(__(
            '%1 remaining at lookup on %2 UTC. Magento balance after its own calls is %3. eBay resets at %4 UTC.',
            number_format($state['reported_remaining']),
            gmdate('Y-m-d H:i', $state['checked_at']),
            number_format($state['remaining']),
            gmdate('Y-m-d H:i', $state['reset_at'])
        )) . '</p>';
        return $html;
    }
}
