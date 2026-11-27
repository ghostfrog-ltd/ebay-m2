<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model;

use GhostFrog\EbayShadow\Model\Config\Source\PotStatus;
use GhostFrog\EbayShadow\Model\ResourceModel\Pot as PotResource;
use Magento\Framework\App\ResourceConnection;

/** Reads and creates pots. */
class Pots
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Cash $cash
    ) {
    }

    /** @return list<array> Running pots whose start date has passed. */
    public function running(string $nowUtc, ?int $potId = null): array
    {
        $db = $this->resource->getConnection();
        $select = $db->select()->from($this->resource->getTableName(PotResource::TABLE))
            ->where('status = ?', PotStatus::RUNNING)->where('start_date <= ?', $nowUtc)->order('pot_id ASC');
        if ($potId !== null) {
            $select->where('pot_id = ?', $potId);
        }
        return $db->fetchAll($select);
    }

    /** @return list<array> */
    public function all(): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchAll($db->select()->from($this->resource->getTableName(PotResource::TABLE))->order('pot_id ASC'));
    }

    public function get(int $potId): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->resource->getTableName(PotResource::TABLE))
            ->where('pot_id = ?', $potId));
        return $row ?: null;
    }

    public function create(string $name, float $capital, string $startDate, array $overrides = []): int
    {
        $db = $this->resource->getConnection();
        $db->insert($this->resource->getTableName(PotResource::TABLE), [
            'name' => $name,
            'starting_capital' => round($capital, 2),
            'start_date' => $startDate,
            'status' => PotStatus::RUNNING,
            'overrides' => json_encode((object)$overrides, JSON_THROW_ON_ERROR),
        ]);
        $id = (int)$db->lastInsertId();
        $this->cash->ensureCapital($id, $capital, $startDate);
        return $id;
    }
}
