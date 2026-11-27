<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Api;

/**
 * Lets another module ask core's existing final-price check to look at ended listings sooner.
 *
 * The caller never contacts eBay. Core only queues listings its own check would take anyway (ended at least
 * 30 minutes ago, no verified price yet, active source), so a request moves work forward without adding any.
 */
interface FinalPriceRequestInterface
{
    /**
     * Queues the listings that are due a final-price check and not already queued.
     *
     * @param int[] $listingIds
     * @return int How many listings were newly queued.
     */
    public function request(array $listingIds): int;
}
