<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\StockUnit;

use GhostFrog\EbayLedger\Model\Purchase\OrderParser;
use Magento\Framework\Data\OptionSourceInterface;

class PurchaseMethod implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => OrderParser::METHOD_AUCTION, 'label' => __('Auction')],
            ['value' => OrderParser::METHOD_BUY_IT_NOW, 'label' => __('Buy It Now')],
            ['value' => OrderParser::METHOD_BEST_OFFER, 'label' => __('Best Offer')],
            ['value' => OrderParser::METHOD_UNKNOWN, 'label' => __('Unknown')],
        ];
    }
}
