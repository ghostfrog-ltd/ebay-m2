<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Api;

/** Read-only access to verified, deduplicated sold comparables (one row per eBay item). */
interface SoldComparablesInterface
{
    /**
     * Verified sales of one model and effective condition grade that ended inside the window.
     *
     * Each row has: item_id, listing_id, final_price, sale_type, end_time. Ordered by end_time.
     *
     * @param string $fromUtc Y-m-d H:i:s, inclusive
     * @param string $untilUtc Y-m-d H:i:s, inclusive
     * @return list<array<string, mixed>>
     */
    public function sales(string $modelKey, string $grade, string $fromUtc, string $untilUtc): array;
}
