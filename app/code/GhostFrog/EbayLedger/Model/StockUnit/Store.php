<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\StockUnit;

use GhostFrog\EbayLedger\Model\Config\Settings;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DuplicateException;

/**
 * Writes imported purchases as stock units, keyed on eBay's order ID, line item ID and unit number, so importing
 * the same purchase again never creates a second unit. Units you have received or planned keep their costs.
 */
class Store
{
    public const TABLE = 'ghostfrog_ebayledger_stock_unit';
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const UNCHANGED = 'unchanged';
    public const SKIPPED = 'skipped';

    /** Statuses whose costs still follow eBay. Later statuses are yours. */
    private const OPEN = [Status::PURCHASED, Status::IN_TRANSIT];
    private const CANCELLED = ['Cancelled', 'CancelPending', 'Inactive'];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Settings $settings
    ) {
    }

    /**
     * @param array $unit From OrderParser::units().
     * @param array|null $prediction Core's ListingLookup row for the item, if core collected it.
     * @param array $rawOrder The eBay order with personal details already removed.
     */
    public function upsert(int $accountId, array $unit, ?array $prediction, array $rawOrder): string
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $existing = $db->fetchRow($db->select()->from($table)
            ->where('ebay_order_id = ?', $unit['ebay_order_id'])
            ->where('ebay_line_item_id = ?', $unit['ebay_line_item_id'])
            ->where('unit_index = ?', (int)$unit['unit_index']));
        $raw = json_encode($rawOrder, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($existing === false) {
            if (in_array($unit['ebay_order_status'], self::CANCELLED, true)) {
                return self::SKIPPED;
            }
            return $this->insert($accountId, $unit, $prediction, $raw);
        }

        $changed = self::changes($existing, $unit, $prediction);
        if ($changed === []) {
            return self::UNCHANGED;
        }
        $changed['raw_payload'] = $raw;
        $db->update($table, $changed, ['stock_unit_id = ?' => (int)$existing['stock_unit_id']]);
        return self::UPDATED;
    }

    private function insert(int $accountId, array $unit, ?array $prediction, string $raw): string
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $row = $unit + [
            'account_id' => $accountId,
            'status' => $unit['shipped_at'] !== null ? Status::IN_TRANSIT : Status::PURCHASED,
            'purchase_method_source' => $unit['purchase_method'] === 'unknown' ? null : 'ebay',
            'raw_payload' => $raw,
            'imported_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($prediction !== null) {
            $row += self::predictionColumns($prediction);
        }
        $db->beginTransaction();
        try {
            $db->insert($table, $row);
            $id = (int)$db->lastInsertId($table);
            // The reference comes from the row's own ID, so it is sequential and never reused.
            $db->update($table, ['reference' => Reference::format($this->settings->skuPrefix(), $id)],
                ['stock_unit_id = ?' => $id]);
            $db->commit();
        } catch (DuplicateException) {
            $db->rollBack();
            return self::UNCHANGED;
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
        return self::CREATED;
    }

    /**
     * What an import changes on an existing unit: eBay's order status and dates always, costs and how it was bought
     * only while the unit is purchased or in transit, and core's prediction only when the unit has none.
     *
     * @return array<string, mixed> Columns whose values differ; empty when the import changes nothing.
     */
    public static function changes(array $existing, array $unit, ?array $prediction): array
    {
        $changes = [
            'ebay_order_status' => $unit['ebay_order_status'],
            'paid_at' => $unit['paid_at'],
            'shipped_at' => $unit['shipped_at'],
        ];
        if (in_array($existing['status'], self::OPEN, true)) {
            $changes += array_intersect_key($unit, array_flip([
                'title', 'currency', 'item_price', 'postage_paid', 'other_costs', 'total_cost',
            ]));
            if ($existing['purchase_method_source'] !== 'manual') {
                $changes['purchase_method'] = $unit['purchase_method'];
                $changes['purchase_method_source'] = $unit['purchase_method'] === 'unknown' ? null : 'ebay';
            }
            if ($existing['status'] === Status::PURCHASED && $unit['shipped_at'] !== null) {
                $changes['status'] = Status::IN_TRANSIT;
            }
        }
        if ($existing['listing_id'] === null && $prediction !== null) {
            $changes += self::predictionColumns($prediction);
        }
        return array_filter($changes, static fn($value, $key) => !self::same($value, $existing[$key]),
            ARRAY_FILTER_USE_BOTH);
    }

    private static function same(mixed $new, mixed $old): bool
    {
        if (is_float($new) || is_int($new)) {
            return is_numeric($old) && abs((float)$new - (float)$old) < 0.005;
        }
        return (string)$new === (string)$old;
    }

    private static function predictionColumns(array $prediction): array
    {
        return [
            'listing_id' => $prediction['listing_id'] ?? null,
            'model_key' => $prediction['model_key'] ?? null,
            'predicted_grade' => $prediction['condition_grade'] ?? null,
            'predicted_resale' => $prediction['sold_median'] ?? null,
            'predicted_comp_count' => $prediction['comp_count'] ?? null,
            'prediction' => json_encode($prediction + ['captured_at' => gmdate('Y-m-d H:i:s')],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }
}
