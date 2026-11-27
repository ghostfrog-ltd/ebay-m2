<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\Extraction;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

class Store
{
    public const TABLE = 'ghostfrog_ebayshadow_extraction';
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const ERROR = 'error';
    public const MAX_ATTEMPTS = 3;

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /** @return array<int, array> Latest extraction per listing for the model and prompt version. */
    public function forListings(array $listingIds, string $model, string $promptVersion): array
    {
        if ($listingIds === []) {
            return [];
        }
        $db = $this->resource->getConnection();
        $rows = [];
        foreach ($db->fetchAll($db->select()->from($this->table())
            ->where('listing_id IN (?)', $listingIds)->where('model = ?', $model)
            ->where('prompt_version = ?', $promptVersion)) as $row) {
            $row['fields'] = $row['fields'] === null ? null : json_decode((string)$row['fields'], true);
            $rows[(int)$row['listing_id']] = $row;
        }
        return $rows;
    }

    /** Listings that still need an attempt: never tried, or failed fewer than MAX_ATTEMPTS times. */
    public function needed(array $listingIds, string $model, string $promptVersion): array
    {
        $done = $this->forListings($listingIds, $model, $promptVersion);
        return array_values(array_filter($listingIds, static fn(int $id): bool => !isset($done[$id])
            || ($done[$id]['status'] === self::ERROR && (int)$done[$id]['attempts'] < self::MAX_ATTEMPTS)));
    }

    public function save(int $listingId, string $model, string $promptVersion, string $hash, string $status,
        ?array $fields, ?string $error): void
    {
        $db = $this->resource->getConnection();
        $db->insertOnDuplicate($this->table(), [
            'listing_id' => $listingId,
            'model' => $model,
            'prompt_version' => $promptVersion,
            'input_hash' => $hash,
            'status' => $status,
            'fields' => $fields === null ? null : json_encode($fields, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            'confidence' => $fields['confidence'] ?? null,
            'error' => $error === null ? null : mb_substr($error, 0, 255),
            'attempts' => 1,
            'extracted_at' => gmdate('Y-m-d H:i:s'),
        ], [
            'input_hash', 'status', 'fields', 'confidence', 'error', 'extracted_at',
            'attempts' => new Expression('attempts + 1'),
        ]);
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
