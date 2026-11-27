<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\SellPlan;

use GhostFrog\EbayLedger\Model\StockUnit\Status;
use InvalidArgumentException;
use Magento\Framework\App\ResourceConnection;

/** Draft and confirmed sell plans. A unit has at most one confirmed plan; older ones are kept as superseded. */
class Plans
{
    public const TABLE = 'ghostfrog_ebayledger_sell_plan';
    private const UNITS = 'ghostfrog_ebayledger_stock_unit';
    /** Units you can plan: received, or planned and not yet listed. */
    public const PLANNABLE = [Status::RECEIVED, Status::PLANNED];

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function latest(int $unitId, string $status): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->resource->getTableName(self::TABLE))
            ->where('stock_unit_id = ?', $unitId)->where('status = ?', $status)->order('plan_id DESC')->limit(1));
        if (!$row) {
            return null;
        }
        foreach (['inputs', 'options'] as $field) {
            $row[$field] = json_decode((string)$row[$field], true) ?: [];
        }
        return $row;
    }

    public function saveDraft(int $unitId, array $inputs, array $built): void
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $row = $this->row($inputs, $built);
        $draft = $this->latest($unitId, 'draft');
        if ($draft) {
            $db->update($table, $row, ['plan_id = ?' => (int)$draft['plan_id']]);
        } else {
            $db->insert($table, $row + ['stock_unit_id' => $unitId, 'status' => 'draft']);
        }
    }

    /** Confirms one option. Nothing else is created here; products follow from the confirmed plan. */
    public function confirm(array $unit, string $option, array $inputs, array $built): int
    {
        if (!in_array($unit['status'], self::PLANNABLE, true)) {
            throw new InvalidArgumentException('Receive the item before planning how to sell it.');
        }
        if (!isset($built['result']['options'][$option])) {
            throw new InvalidArgumentException('That option is not available for this item.');
        }
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $unitId = (int)$unit['stock_unit_id'];
        $db->beginTransaction();
        try {
            $db->update($table, ['status' => 'superseded'],
                ['stock_unit_id = ?' => $unitId, 'status IN (?)' => ['confirmed', 'draft']]);
            $db->insert($table, $this->row($inputs, $built) + [
                'stock_unit_id' => $unitId,
                'status' => 'confirmed',
                'chosen_option' => $option,
                'confirmed_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $planId = (int)$db->lastInsertId($table);
            $db->update($this->resource->getTableName(self::UNITS), ['status' => Status::PLANNED],
                ['stock_unit_id = ?' => $unitId]);
            $db->commit();
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
        return $planId;
    }

    private function row(array $inputs, array $built): array
    {
        return [
            'inputs' => json_encode($inputs, JSON_UNESCAPED_UNICODE),
            'options' => json_encode($built['result'] + ['grade' => $built['grade']], JSON_UNESCAPED_UNICODE),
            'recommended_option' => $built['result']['recommended'],
            'template_id' => $built['template']['template_id'] ?? null,
        ];
    }
}
