<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Api;

/** Core's fee rules, so other modules price purchases the same way as the ranking. */
interface PricingRulesInterface
{
    /** eBay UK Buyer Protection fee in GBP that a buyer pays on top of the item price. */
    public function buyerProtection(float $price): float;
}
