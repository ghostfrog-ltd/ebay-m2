<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Service;

use GhostFrog\Ebay\Api\PricingRulesInterface;
use GhostFrog\Ebay\Model\Comps\Pricing;

final class PricingRules implements PricingRulesInterface
{
    public function buyerProtection(float $price): float
    {
        return Pricing::buyerProtection($price);
    }
}
