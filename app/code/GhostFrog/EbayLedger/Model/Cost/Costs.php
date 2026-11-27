<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Cost;

use InvalidArgumentException;
use Magento\Framework\App\ResourceConnection;

/** Costs you enter yourself, against a stock unit or one of its products. */
class Costs
{
    public const TABLE = 'ghostfrog_ebayledger_cost';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function save(array $input): int
    {
        $db = $this->resource->getConnection();
        $unitId = (int)($input['stock_unit_id'] ?? 0);
        $productId = (int)($input['ledger_product_id'] ?? 0) ?: null;
        $type = (string)($input['cost_type'] ?? '');
        $amount = round((float)str_replace(['£', ','], '', (string)($input['amount'] ?? '')), 2);
        $date = (string)($input['incurred_on'] ?? '') ?: gmdate('Y-m-d');
        if ($unitId <= 0 || !isset(CostType::LABELS[$type]) || $amount <= 0) {
            throw new InvalidArgumentException('Choose the stock unit and the kind of cost, and enter an amount above £0.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('The date is not valid.');
        }
        if ($productId !== null && (int)$db->fetchOne($db->select()
            ->from($this->resource->getTableName('ghostfrog_ebayledger_product'), ['stock_unit_id'])
            ->where('ledger_product_id = ?', $productId)) !== $unitId) {
            throw new InvalidArgumentException('That product belongs to a different stock unit.');
        }
        $row = [
            'stock_unit_id' => $unitId,
            'ledger_product_id' => $productId,
            'cost_type' => $type,
            'amount' => $amount,
            'incurred_on' => $date,
            'note' => mb_substr(trim((string)($input['note'] ?? '')), 0, 255) ?: null,
        ];
        $id = (int)($input['cost_id'] ?? 0);
        if ($id > 0) {
            $db->update($this->resource->getTableName(self::TABLE), $row, ['cost_id = ?' => $id]);
            return $id;
        }
        $db->insert($this->resource->getTableName(self::TABLE), $row);
        return (int)$db->lastInsertId($this->resource->getTableName(self::TABLE));
    }

    public function delete(int $costId): void
    {
        $this->resource->getConnection()->delete($this->resource->getTableName(self::TABLE), ['cost_id = ?' => $costId]);
    }
}
