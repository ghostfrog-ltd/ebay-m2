<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Api;

/**
 * Read-only view of live auction opportunities with the valuation evidence behind them.
 *
 * For consumers such as GhostFrog_EbayShadow that must not query core tables directly.
 */
interface AuctionCandidatesInterface
{
    /**
     * Live auctions in the Opportunities index whose end time falls in the window, valued by core's ranking.
     *
     * Each row has: listing_id, external_id, title, url, source (domain), item_type, model_key,
     * condition_grade, price_current, bids_count, end_time, last_seen_at, last_price_checked_at,
     * sold_median, resale_basis, comp_count, comp_minimum, comps_computed_at, expected_profit,
     * max_safe_buy, shipping (inbound plus outbound), inbound_postage (null when the listing gave none),
     * selling_fee_percent, text_grade, text_confidence, text_review, visual_grade, visual_confidence,
     * visual_review.
     *
     * @param string $fromUtc Y-m-d H:i:s
     * @param string $untilUtc Y-m-d H:i:s
     * @return array<int, array<string, mixed>> Keyed by listing ID.
     */
    public function endingBetween(string $fromUtc, string $untilUtc): array;
}
