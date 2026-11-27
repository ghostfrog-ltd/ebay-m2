<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Api;

/**
 * Core's view of eBay items it collected, for modules that need to know what core predicted about an item.
 */
interface ListingLookupInterface
{
    /**
     * Each row has: listing_id, item_id, title, source, status, sale_type, best_offer, model_key, item_type,
     * condition_grade (core's effective grade; null when core would not value the item), text_grade,
     * visual_grade, sold_median, comp_count, resale_basis, comps_computed_at, selling_fee_percent, shipping.
     *
     * @param string[] $itemIds eBay item IDs
     * @return array<string, array<string, mixed>> Keyed by item ID; items core never collected are left out.
     */
    public function byItemIds(array $itemIds): array;
}
