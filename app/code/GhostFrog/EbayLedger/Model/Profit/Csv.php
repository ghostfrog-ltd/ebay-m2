<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Profit;

/** A CSV for your records and tax return: every sale, every closed unit, every cost and every eBay fee. */
class Csv
{
    public function __construct(private readonly Report $report, private readonly \Magento\Framework\App\ResourceConnection $resource)
    {
    }

    public function build(): string
    {
        $products = $this->report->products();
        $units = $this->report->units($products);
        $out = fopen('php://temp', 'r+');
        self::row($out, ['Record', 'Date', 'Reference', 'SKU', 'Description', 'Income', 'Allocated cost', 'eBay fees', 'Postage',
            'Packaging', 'Other costs', 'Profit', 'eBay order']);
        foreach ($products as $p) {
            if ($p['sold']) {
                self::row($out, ['Sale', substr((string)$p['sold_at'], 0, 10), $p['reference'], $p['sku'], $p['part_name'] . ' – ' . $p['title'],
                    self::n($p['income']), self::n($p['allocated_cost']), self::n($p['ebay_fees']), self::n($p['postage_total']),
                    self::n($p['packaging']), self::n($p['other_costs']), self::n($p['profit']), $p['ebay_order_id']]);
            }
        }
        foreach ($units as $u) {
            if (in_array($u['status'], ['returned_to_seller', 'written_off'], true)) {
                self::row($out, [$u['status'] === 'written_off' ? 'Written off' : 'Returned to seller',
                    (string)($u['received_at'] ?: substr((string)$u['purchased_at'], 0, 10)), $u['reference'], '', $u['title'],
                    self::n($u['refund_amount']), self::n($u['total_cost']), '', '', '', '', self::n($u['profit']), '']);
            }
        }
        $db = $this->resource->getConnection();
        $purchases = $db->fetchAll($db->select()->from($this->resource->getTableName('ghostfrog_ebayledger_stock_unit'),
            ['reference', 'purchased_at', 'title', 'total_cost', 'ebay_order_id'])->order('purchased_at'));
        foreach ($purchases as $row) {
            self::row($out, ['Purchase', substr((string)$row['purchased_at'], 0, 10), $row['reference'], '', $row['title'], '',
                self::n($row['total_cost']), '', '', '', '', '', $row['ebay_order_id']]);
        }
        $costs = $db->fetchAll($db->select()->from(['c' => $this->resource->getTableName('ghostfrog_ebayledger_cost')])
            ->join(['u' => $this->resource->getTableName('ghostfrog_ebayledger_stock_unit')], 'u.stock_unit_id = c.stock_unit_id', ['reference'])
            ->joinLeft(['p' => $this->resource->getTableName('ghostfrog_ebayledger_product')], 'p.ledger_product_id = c.ledger_product_id', ['sku'])
            ->order('c.incurred_on'));
        foreach ($costs as $row) {
            $postage = $row['cost_type'] === 'postage' ? self::n($row['amount']) : '';
            $packaging = $row['cost_type'] === 'packaging' ? self::n($row['amount']) : '';
            $other = in_array($row['cost_type'], ['repair', 'other'], true) ? self::n($row['amount']) : '';
            self::row($out, ['My cost', $row['incurred_on'], $row['reference'], $row['sku'], trim($row['cost_type'] . ' ' . $row['note']),
                '', '', '', $postage, $packaging, $other, '', '']);
        }
        $fees = $db->fetchAll($db->select()->from($this->resource->getTableName('ghostfrog_ebayledger_fee'),
            ['booked_at', 'fee_type', 'transaction_type', 'amount', 'ebay_order_id'])->order('booked_at'));
        foreach ($fees as $row) {
            self::row($out, ['eBay fee', substr((string)$row['booked_at'], 0, 10), '', '', $row['transaction_type'] . ' ' . $row['fee_type'],
                '', '', self::n($row['amount']), '', '', '', '', $row['ebay_order_id']]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return (string)$csv;
    }

    private static function row($handle, array $fields): void
    {
        fputcsv($handle, $fields, ',', '"', '');
    }

    private static function n(mixed $value): string
    {
        return $value === null || $value === '' ? '' : number_format((float)$value, 2, '.', '');
    }
}
