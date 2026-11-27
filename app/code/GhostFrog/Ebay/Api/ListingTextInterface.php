<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Api;

/** The listing text core already captured (title, description, condition notes and item specifics). */
interface ListingTextInterface
{
    /**
     * @return array{title: string, captured_text: array<string, string>}|null
     *     Null when the listing does not exist.
     */
    public function evidence(int $listingId): ?array;
}
