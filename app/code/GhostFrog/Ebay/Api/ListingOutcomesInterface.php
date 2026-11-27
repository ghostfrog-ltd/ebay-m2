<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Api;

/** Read-only listing status and verified final price, as recorded by core's final-price checks. */
interface ListingOutcomesInterface
{
    /**
     * Each row has: listing_id, status (live, sold, ended, unknown, ...), finalized, final_price,
     * bids_count, end_time, price_current, outcome_reason, last_seen_at.
     *
     * @param int[] $listingIds
     * @return array<int, array<string, mixed>> Keyed by listing ID; missing listings are left out.
     */
    public function forListings(array $listingIds): array;
}
