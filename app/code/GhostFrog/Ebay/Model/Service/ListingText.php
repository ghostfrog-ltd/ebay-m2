<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Service;

use GhostFrog\Ebay\Api\ListingTextInterface;
use GhostFrog\Ebay\Model\Condition\TextEvidence;
use GhostFrog\Ebay\Model\ResourceModel\Listing;
use Magento\Framework\App\ResourceConnection;

/** The same title and captured text that core's text condition check sends to Ollama. Read-only. */
final class ListingText implements ListingTextInterface
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function evidence(int $listingId): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->resource->getTableName(Listing::TABLE), ['title', 'raw_attrs'])
            ->where('id = ?', $listingId));
        if (!$row) {
            return null;
        }
        $attrs = json_decode((string)$row['raw_attrs'], true);
        return [
            'title' => mb_substr(trim((string)$row['title']), 0, 255),
            'captured_text' => TextEvidence::fromAttrs(is_array($attrs) ? $attrs : []),
        ];
    }
}
