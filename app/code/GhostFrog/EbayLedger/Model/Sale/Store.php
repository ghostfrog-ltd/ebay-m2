<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Sale;

use Magento\Framework\App\ResourceConnection;

/** Sale lines keyed on eBay's order and line IDs, so importing a sale again never duplicates it. */
class Store
{
    public const TABLE = 'ghostfrog_ebayledger_sale_line';
    private const PRODUCTS = 'ghostfrog_ebayledger_product';
    private const EBAY_FIELDS = [
        'ebay_item_id', 'sku', 'title', 'quantity', 'currency', 'line_price', 'postage', 'refunded', 'sold_at',
        'payment_status', 'fulfillment_status', 'cancelled',
    ];

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /** @return string created, updated or unchanged */
    public function upsert(int $accountId, array $line, array $rawOrder): string
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $existing = $db->fetchRow($db->select()->from($table)
            ->where('ebay_order_id = ?', $line['ebay_order_id'])->where('ebay_line_item_id = ?', $line['ebay_line_item_id']));
        $raw = json_encode($rawOrder, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($existing === false) {
            $db->insert($table, $line + ['account_id' => $accountId, 'raw_payload' => $raw, 'imported_at' => gmdate('Y-m-d H:i:s')]
                + $this->match($line));
            return 'created';
        }
        $changes = [];
        foreach (self::EBAY_FIELDS as $field) {
            if ((string)$line[$field] !== (string)$existing[$field]
                && !(is_numeric($line[$field]) && is_numeric($existing[$field]) && abs((float)$line[$field] - (float)$existing[$field]) < 0.005)) {
                $changes[$field] = $line[$field];
            }
        }
        // Unmatched lines are tried again: you may have created the product or fixed the eBay custom label since.
        if ($existing['match_status'] === 'unmatched' && $existing['magento_order_id'] === null) {
            $match = $this->match($line + ['sale_line_id' => $existing['sale_line_id']]);
            if ($match['match_status'] !== 'unmatched' || $match['match_note'] !== $existing['match_note']) {
                $changes += $match;
            }
        }
        if ($changes === []) {
            return 'unchanged';
        }
        $db->update($table, $changes + ['raw_payload' => $raw], ['sale_line_id = ?' => (int)$existing['sale_line_id']]);
        return 'updated';
    }

    /** Links a sale by hand to a Ledger product, when its eBay listing had no or the wrong custom label. */
    public function link(int $saleLineId, int $ledgerProductId): string
    {
        $db = $this->resource->getConnection();
        $line = $db->fetchRow($db->select()->from($this->resource->getTableName(self::TABLE))->where('sale_line_id = ?', $saleLineId));
        if (!$line || $line['magento_order_id'] !== null) {
            throw new \InvalidArgumentException('This sale is already on a Magento order and cannot be relinked.');
        }
        $product = $db->fetchRow($db->select()->from($this->resource->getTableName(self::PRODUCTS))
            ->where('ledger_product_id = ?', $ledgerProductId)->where('status = ?', 'active'));
        if (!$product) {
            throw new \InvalidArgumentException('Choose a product that is still for sale.');
        }
        $taken = $db->fetchOne($db->select()->from($this->resource->getTableName(self::TABLE), ['sale_line_id'])
            ->where('ledger_product_id = ?', $ledgerProductId)->where('sale_line_id <> ?', $saleLineId)
            ->where('match_status IN (?)', ['matched', 'linked']));
        if ($taken !== false) {
            throw new \InvalidArgumentException(sprintf('%s is already matched to another sale.', $product['sku']));
        }
        $db->update($this->resource->getTableName(self::TABLE), [
            'match_status' => 'linked', 'ledger_product_id' => $ledgerProductId,
            'match_note' => sprintf('Linked by hand to %s.', $product['sku']),
        ], ['sale_line_id = ?' => $saleLineId]);
        return (string)$line['ebay_order_id'];
    }

    /** Marks a sale as nothing to do with Ledger (or back to unmatched). */
    public function ignore(int $saleLineId, bool $ignore): string
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $line = $db->fetchRow($db->select()->from($table)->where('sale_line_id = ?', $saleLineId));
        if (!$line || $line['magento_order_id'] !== null) {
            throw new \InvalidArgumentException('This sale is already on a Magento order.');
        }
        $db->update($table, $ignore
            ? ['match_status' => 'ignored', 'ledger_product_id' => null, 'match_note' => 'Not a Ledger item.']
            : ['match_status' => 'unmatched', 'ledger_product_id' => null, 'match_note' => null],
            ['sale_line_id = ?' => $saleLineId]);
        return (string)$line['ebay_order_id'];
    }

    private function match(array $line): array
    {
        $db = $this->resource->getConnection();
        $products = [];
        if ($line['sku'] !== null) {
            foreach ($db->fetchAll($db->select()->from($this->resource->getTableName(self::PRODUCTS))
                ->where('sku = ?', $line['sku'])->where('status IN (?)', ['active', 'sold'])) as $row) {
                $products[$row['sku']] = $row;
            }
        }
        $claimed = [];
        if ($products !== []) {
            $select = $db->select()->from($this->resource->getTableName(self::TABLE),
                ['ledger_product_id', 'owner' => new \Magento\Framework\DB\Sql\Expression("CONCAT(ebay_order_id, '/', ebay_line_item_id)")])
                ->where('ledger_product_id IN (?)', array_column($products, 'ledger_product_id'))
                ->where('match_status IN (?)', ['matched', 'linked']);
            $claimed = $db->fetchPairs($select);
        }
        return Matcher::match($line, $products, $claimed);
    }
}
