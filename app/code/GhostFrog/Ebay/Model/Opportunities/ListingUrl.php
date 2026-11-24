<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Opportunities;

use GhostFrog\Ebay\Model\Comps\Listings;

final class ListingUrl
{
    public static function resolve(?string $url, string $externalId): ?string
    {
        $parts = parse_url($url ?? '');
        if (($parts['scheme'] ?? null) === 'https' && !isset($parts['user'])
            && preg_match('/^(?:[a-z0-9-]+\.)*ebay\.(?:co\.uk|com)$/iD', $parts['host'] ?? '')
        ) {
            return $url;
        }

        $id = Listings::itemId($externalId);
        return ctype_digit($id) ? "https://www.ebay.co.uk/itm/$id" : null;
    }
}
