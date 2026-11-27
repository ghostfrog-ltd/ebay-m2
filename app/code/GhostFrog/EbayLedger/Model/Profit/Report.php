<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Profit;

use GhostFrog\EbayLedger\Model\SellPlan\Calculator as PlanCalculator;
use Magento\Framework\App\ResourceConnection;

/**
 * Everything the Profit Dashboard and its CSV show, from Ledger's own tables and the Magento orders it made.
 */
class Report
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /** @return list<array> One row per product made from a stock unit, with its sale, fees, costs and profit. */
    public function products(): array
    {
        $db = $this->resource->getConnection();
        $t = fn(string $name) => $this->resource->getTableName($name);
        $sale = $db->select()->from(['s' => $t('ghostfrog_ebayledger_sale_line')], [
            'ledger_product_id', 'sold_at' => 'MAX(s.sold_at)', 'sale_price' => 'SUM(s.line_price)',
            'postage_charged' => 'SUM(s.postage)', 'refunded' => 'SUM(s.refunded)', 'sale_account_id' => 'MAX(s.account_id)',
            'ebay_order_id' => 'MAX(s.ebay_order_id)',
        ])->where('s.match_status IN (?)', ['matched', 'linked'])->where('s.cancelled = 0')
            ->where('s.ledger_product_id IS NOT NULL')->group('s.ledger_product_id');
        $fees = $db->select()->from(['f' => $t('ghostfrog_ebayledger_fee')], [
            'ledger_product_id',
            'ebay_fees' => "SUM(CASE WHEN f.fee_type <> 'SHIPPING_LABEL' AND f.transaction_type <> 'SHIPPING_LABEL' THEN f.amount ELSE 0 END)",
            'label_fees' => "SUM(CASE WHEN f.fee_type = 'SHIPPING_LABEL' OR f.transaction_type = 'SHIPPING_LABEL' THEN f.amount ELSE 0 END)",
        ])->where('f.ledger_product_id IS NOT NULL')->group('f.ledger_product_id');
        $costs = $db->select()->from(['c' => $t('ghostfrog_ebayledger_cost')], [
            'ledger_product_id',
            'postage_cost' => "SUM(CASE WHEN c.cost_type = 'postage' THEN c.amount ELSE 0 END)",
            'packaging' => "SUM(CASE WHEN c.cost_type = 'packaging' THEN c.amount ELSE 0 END)",
            'other_costs' => "SUM(CASE WHEN c.cost_type IN ('repair', 'other') THEN c.amount ELSE 0 END)",
        ])->where('c.ledger_product_id IS NOT NULL')->group('c.ledger_product_id');
        $payout = $db->select()->from(['sp' => $t('ghostfrog_ebayledger_sale_payout')], ['ebay_order_id', 'payout_at' => 'MIN(po.payout_at)'])
            ->join(['po' => $t('ghostfrog_ebayledger_payout')], 'po.payout_id = sp.payout_id', [])->group('sp.ebay_order_id');

        $rows = $db->fetchAll($db->select()->from(['p' => $t('ghostfrog_ebayledger_product')],
            ['ledger_product_id', 'stock_unit_id', 'sku', 'part_name', 'planned_price', 'allocated_cost', 'status', 'created_at'])
            ->join(['u' => $t('ghostfrog_ebayledger_stock_unit')], 'u.stock_unit_id = p.stock_unit_id',
                ['reference', 'title', 'model_key', 'account_id'])
            ->joinLeft(['s' => $sale], 's.ledger_product_id = p.ledger_product_id',
                ['sold_at', 'sale_price', 'postage_charged', 'refunded', 'sale_account_id', 'ebay_order_id'])
            ->joinLeft(['f' => $fees], 'f.ledger_product_id = p.ledger_product_id', ['ebay_fees', 'label_fees'])
            ->joinLeft(['c' => $costs], 'c.ledger_product_id = p.ledger_product_id', ['postage_cost', 'packaging', 'other_costs'])
            ->joinLeft(['po' => $payout], 'po.ebay_order_id = s.ebay_order_id', ['payout_at'])
            ->where('p.status <> ?', 'replaced')->order(['s.sold_at DESC', 'p.sku']));
        foreach ($rows as &$row) {
            $row['sold'] = in_array($row['status'], ['sold', 'refunded'], true) && $row['sold_at'] !== null;
            $row += $row['sold'] ? Calculator::product($row) : ['income' => null, 'postage_total' => null, 'costs' => null, 'profit' => null];
        }
        return $rows;
    }

    /** @return list<array> One row per stock unit with profit so far, planned and whole-item predictions, timings and grades. */
    public function units(array $products): array
    {
        $db = $this->resource->getConnection();
        $t = fn(string $name) => $this->resource->getTableName($name);
        $unitCosts = $db->fetchPairs($db->select()->from($t('ghostfrog_ebayledger_cost'), ['stock_unit_id', 'SUM(amount)'])
            ->where('ledger_product_id IS NULL')->group('stock_unit_id'));
        $plans = [];
        foreach ($db->fetchAll($db->select()->from($t('ghostfrog_ebayledger_sell_plan'),
            ['stock_unit_id', 'chosen_option', 'options', 'confirmed_at'])->where('status = ?', 'confirmed')) as $plan) {
            $plans[(int)$plan['stock_unit_id']] = $plan;
        }
        $byUnit = [];
        foreach ($products as $product) {
            $byUnit[(int)$product['stock_unit_id']][] = $product;
        }
        $units = $db->fetchAll($db->select()->from($t('ghostfrog_ebayledger_stock_unit'), [
            'stock_unit_id', 'reference', 'title', 'account_id', 'model_key', 'status', 'total_cost', 'refund_amount',
            'purchased_at', 'received_at', 'predicted_grade', 'actual_grade',
        ])->order('stock_unit_id DESC'));
        foreach ($units as &$unit) {
            $id = (int)$unit['stock_unit_id'];
            $products = $byUnit[$id] ?? [];
            $unit += Calculator::unit($unit, $products, (float)($unitCosts[$id] ?? 0));
            $plan = $plans[$id] ?? null;
            $options = $plan ? (json_decode((string)$plan['options'], true)['options'] ?? []) : [];
            $unit['plan'] = $plan ? (PlanCalculator::LABELS[$plan['chosen_option']] ?? $plan['chosen_option']) : null;
            $unit['planned_profit'] = $plan ? ($options[$plan['chosen_option']]['expected_profit'] ?? null) : null;
            $unit['whole_predicted'] = $options['whole']['expected_profit'] ?? null;
            $soldAt = array_filter(array_column($products, 'sold_at'));
            $unit['days_to_receive'] = Calculator::days(substr((string)$unit['purchased_at'], 0, 10) ?: null, $unit['received_at']);
            $unit['days_to_plan'] = Calculator::days($unit['received_at'], $plan ? substr((string)$plan['confirmed_at'], 0, 10) : null);
            $unit['days_to_sell'] = Calculator::average(array_map(static fn($p) => $p['sold'] ? Calculator::days(substr((string)$p['created_at'], 0, 10),
                substr((string)$p['sold_at'], 0, 10)) : null, $products));
            $unit['days_to_payout'] = Calculator::average(array_map(static fn($p) => $p['sold'] && $p['payout_at']
                ? Calculator::days(substr((string)$p['sold_at'], 0, 10), substr((string)$p['payout_at'], 0, 10)) : null, $products));
            $unit['last_sold_at'] = $soldAt === [] ? null : max($soldAt);
        }
        return $units;
    }

    /** Totals by month sold, model and account, from sold products and closed units. */
    public function totals(array $products, array $units): array
    {
        $accounts = $this->resource->getConnection()->fetchPairs($this->resource->getConnection()->select()
            ->from($this->resource->getTableName('ghostfrog_ebayledger_account'), ['account_id', 'label']));
        $totals = ['month' => [], 'model' => [], 'account' => []];
        $add = static function (array &$bucket, string $key, float $income, float $profit, int $sales) {
            $bucket[$key] ??= ['income' => 0.0, 'profit' => 0.0, 'sales' => 0];
            $bucket[$key]['income'] += $income;
            $bucket[$key]['profit'] += $profit;
            $bucket[$key]['sales'] += $sales;
        };
        foreach ($products as $product) {
            if ($product['sold']) {
                $add($totals['month'], substr((string)$product['sold_at'], 0, 7), (float)$product['income'], (float)$product['profit'], 1);
                $add($totals['model'], (string)($product['model_key'] ?: 'unknown'), (float)$product['income'], (float)$product['profit'], 1);
                $add($totals['account'], (string)($accounts[$product['sale_account_id']] ?? 'unknown'), (float)$product['income'], (float)$product['profit'], 1);
            }
        }
        foreach ($units as $unit) {
            if (in_array($unit['status'], ['returned_to_seller', 'written_off'], true) && $unit['profit'] !== null) {
                $add($totals['month'], substr((string)($unit['received_at'] ?: $unit['purchased_at']), 0, 7), 0.0, (float)$unit['profit'], 0);
                $add($totals['model'], (string)($unit['model_key'] ?: 'unknown'), 0.0, (float)$unit['profit'], 0);
                $add($totals['account'], (string)($accounts[$unit['account_id']] ?? 'unknown'), 0.0, (float)$unit['profit'], 0);
            }
        }
        foreach ($totals as &$group) {
            krsort($group);
        }
        return $totals;
    }

    /** Predicted against actual grades, for units you have graded. */
    public function grades(array $units): array
    {
        $graded = array_filter($units, static fn($unit) => $unit['predicted_grade'] && $unit['actual_grade']);
        $matrix = [];
        foreach ($graded as $unit) {
            $matrix[$unit['predicted_grade']][$unit['actual_grade']] = ($matrix[$unit['predicted_grade']][$unit['actual_grade']] ?? 0) + 1;
        }
        $exact = count(array_filter($graded, static fn($unit) => $unit['predicted_grade'] === $unit['actual_grade']));
        return ['graded' => count($graded), 'exact' => $exact, 'matrix' => $matrix];
    }
}
