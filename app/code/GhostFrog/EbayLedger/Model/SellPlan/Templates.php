<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\SellPlan;

use Magento\Framework\App\ResourceConnection;

/** Parts templates, matched to stock units by core's model key. */
class Templates
{
    public const TABLE = 'ghostfrog_ebayledger_parts_template';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /** The active template that lists this model key, with its parts decoded; null when none does. */
    public function forModel(?string $modelKey): ?array
    {
        $modelKey = strtolower(trim((string)$modelKey));
        if ($modelKey === '') {
            return null;
        }
        $db = $this->resource->getConnection();
        $rows = $db->fetchAll($db->select()->from($this->resource->getTableName(self::TABLE))
            ->where('active = 1')->order('template_id ASC'));
        foreach ($rows as $row) {
            $keys = array_map('strtolower', array_filter(array_map('trim', preg_split('/[\s,]+/', (string)$row['model_keys']) ?: [])));
            if (in_array($modelKey, $keys, true)) {
                return self::decode($row);
            }
        }
        return null;
    }

    public function get(int $templateId): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->resource->getTableName(self::TABLE))->where('template_id = ?', $templateId));
        return $row ? self::decode($row) : null;
    }

    public static function decode(array $row): array
    {
        $parts = json_decode((string)($row['parts'] ?? ''), true);
        $row['parts'] = array_values(array_filter(is_array($parts) ? $parts : [],
            static fn($part) => is_array($part) && trim((string)($part['name'] ?? '')) !== ''));
        return $row;
    }
}
