<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

/**
 * A pot's cash as append-only movements. The balance is always their sum, never a stored number.
 *
 * Every movement has an idempotency key such as "buy:12", and the table keeps one row per key, so a job that
 * runs twice records each debit or credit once.
 */
class Cash
{
    public const TABLE = 'ghostfrog_ebayshadow_cash_movement';
    public const CAPITAL = 'capital';
    public const BID_RESERVE = 'bid_reserve';
    public const BID_RELEASE = 'bid_release';
    public const BUY_DEBIT = 'buy_debit';
    public const PAYOUT_CREDIT = 'payout_credit';
    public const WRITE_OFF = 'write_off';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /** @return bool True when this call recorded the movement, false when the key was already recorded. */
    public function record(
        int $potId,
        string $type,
        float $amount,
        string $key,
        string $occurredAt,
        ?int $itemId = null,
        ?string $note = null
    ): bool {
        $db = $this->resource->getConnection();
        $inserted = $db->insertOnDuplicate($this->table(), [
            'pot_id' => $potId,
            'item_id' => $itemId,
            'type' => $type,
            'amount' => round($amount, 2),
            'idempotency_key' => $key,
            'occurred_at' => $occurredAt,
            'note' => $note === null ? null : mb_substr($note, 0, 255),
        ], ['idempotency_key']);
        return $inserted === 1;
    }

    public function balance(int $potId, ?string $until = null): float
    {
        $db = $this->resource->getConnection();
        $select = $db->select()->from($this->table(), [new Expression('COALESCE(SUM(amount), 0)')])
            ->where('pot_id = ?', $potId);
        if ($until !== null) {
            $select->where('occurred_at <= ?', $until);
        }
        return round((float)$db->fetchOne($select), 2);
    }

    /** Records the pot's starting capital once. */
    public function ensureCapital(int $potId, float $capital, string $startDate): void
    {
        $this->record($potId, self::CAPITAL, $capital, "capital:$potId", $startDate, null, 'Starting capital');
    }

    /** @return list<array{occurred_at: string, amount: string, type: string}> */
    public function movements(int $potId): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchAll($db->select()->from($this->table(), ['occurred_at', 'amount', 'type'])
            ->where('pot_id = ?', $potId)->order(['occurred_at ASC', 'movement_id ASC']));
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
