<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model;

use GhostFrog\EbayShadow\Model\Engine\Lifecycle;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

/** Read-only figures for the dashboard and the daily summary. */
class Report
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Pots $pots,
        private readonly Cash $cash
    ) {
    }

    public function pots(): array
    {
        return $this->pots->all();
    }

    public function summary(int $potId): array
    {
        $db = $this->resource->getConnection();
        $items = $this->table(Decisions::ITEMS);
        $sales = $this->table(Sales::TABLE);
        $decisions = $this->table(Decisions::TABLE);
        $pot = $this->pots->get($potId) ?? [];

        $tied = 0.0;
        foreach ($this->tiedUpByStage($potId) as $amount) {
            $tied += $amount;
        }
        $auctions = $db->fetchPairs($db->select()->from($items, ['stage', new Expression('COUNT(*)')])
            ->where('pot_id = ?', $potId)->group('stage'));
        $won = array_sum(array_intersect_key($auctions, array_flip(array_diff(Lifecycle::STAGES, ['bidding', 'lost', 'void']))));
        $lost = (int)($auctions['lost'] ?? 0);
        $sale = $db->fetchRow($db->select()->from(['s' => $sales], [
            'sold' => new Expression('COUNT(*)'),
            'profitable' => new Expression('SUM(s.realised_profit > 0)'),
            'avg_error' => new Expression('AVG(s.error_percent)'),
            'avg_abs_error' => new Expression('AVG(ABS(s.error_percent))'),
            'avg_days' => new Expression('AVG(s.days_to_sell)'),
        ])->join(['i' => $items], 'i.item_id = s.item_id', [])->where('s.pot_id = ?', $potId)
            ->where("i.stage IN ('outbound', 'paid_out', 'banked', 'written_off')"));
        $profit = static fn(array $stages) => (float)$db->fetchOne($db->select()->from(['s' => $sales],
            [new Expression('COALESCE(SUM(s.realised_profit), 0)')])
            ->join(['i' => $items], 'i.item_id = s.item_id', [])->where('s.pot_id = ?', $potId)
            ->where('i.stage IN (?)', $stages));

        return [
            'name' => $pot['name'] ?? '',
            'status' => $pot['status'] ?? '',
            'starting_capital' => (float)($pot['starting_capital'] ?? 0),
            'cash' => $this->cash->balance($potId),
            'tied_up' => round($tied, 2),
            'bids' => array_sum($auctions),
            'bought' => (int)$won,
            'lost' => $lost,
            'void' => (int)($auctions['void'] ?? 0),
            'win_rate' => $won + $lost > 0 ? round(100 * $won / ($won + $lost), 1) : null,
            'spend' => (float)$db->fetchOne($db->select()->from($items, [new Expression('COALESCE(SUM(buy_cost), 0)')])
                ->where('pot_id = ?', $potId)->where('buy_cost IS NOT NULL')),
            'sold' => (int)$sale['sold'],
            'hit_rate' => (int)$sale['sold'] > 0 ? round(100 * (int)$sale['profitable'] / (int)$sale['sold'], 1) : null,
            'avg_error' => $sale['avg_error'] === null ? null : round((float)$sale['avg_error'], 1),
            'avg_abs_error' => $sale['avg_abs_error'] === null ? null : round((float)$sale['avg_abs_error'], 1),
            'avg_days_to_sell' => $sale['avg_days'] === null ? null : round((float)$sale['avg_days'], 1),
            'realised' => round($profit(['paid_out', 'banked', 'written_off']), 2),
            'banked' => round($profit(['banked', 'written_off']), 2),
            'missed_profit' => (float)$db->fetchOne($db->select()->from($decisions,
                [new Expression('COALESCE(SUM(cautious_profit), 0)')])
                ->where('pot_id = ?', $potId)->where('outcome = ?', 'skipped')->where('reason = ?', 'no_cash')),
            'missed_count' => (int)$db->fetchOne($db->select()->from($decisions, [new Expression('COUNT(*)')])
                ->where('pot_id = ?', $potId)->where('outcome = ?', 'skipped')->where('reason = ?', 'no_cash')),
            'days_no_cash' => (int)$db->fetchOne($db->select()->from($this->table(Decisions::RUNS),
                [new Expression('COUNT(DISTINCT DATE(run_at))')])->where('pot_id = ?', $potId)->where('no_cash > 0')),
            'decisions' => (int)$db->fetchOne($db->select()->from($decisions, [new Expression('COUNT(*)')])
                ->where('pot_id = ?', $potId)),
        ];
    }

    /** @return array<string, float> Cash committed per open stage. */
    public function tiedUpByStage(int $potId): array
    {
        $db = $this->resource->getConnection();
        $rows = $db->fetchPairs($db->select()->from($this->table(Decisions::ITEMS), [
            'stage', new Expression("SUM(IF(stage = 'bidding', reserved, buy_cost))"),
        ])->where('pot_id = ?', $potId)->where('stage IN (?)', Lifecycle::OPEN)->group('stage'));
        $result = [];
        foreach (Lifecycle::OPEN as $stage) {
            $result[$stage] = round((float)($rows[$stage] ?? 0), 2);
        }
        return $result;
    }

    /** End-of-day cash balance, one point per day from the pot's first movement. @return array<string, float> */
    public function cashSeries(int $potId): array
    {
        $series = [];
        $balance = 0.0;
        foreach ($this->cash->movements($potId) as $movement) {
            $balance += (float)$movement['amount'];
            $series[substr($movement['occurred_at'], 0, 10)] = round($balance, 2);
        }
        if ($series === []) {
            return [];
        }
        $filled = [];
        $day = strtotime(array_key_first($series) . ' UTC');
        $last = max(strtotime(array_key_last($series) . ' UTC'), strtotime(gmdate('Y-m-d') . ' UTC'));
        $value = 0.0;
        for (; $day <= $last; $day += 86400) {
            $key = gmdate('Y-m-d', $day);
            $value = $series[$key] ?? $value;
            $filled[$key] = $value;
        }
        return $filled;
    }

    public function byCategory(int $potId): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchAll($db->select()->from(['d' => $this->table(Decisions::TABLE)], [
            'category',
            'candidates' => new Expression('COUNT(*)'),
            'bids' => new Expression("SUM(d.outcome = 'bought')"),
        ])->joinLeft(['i' => $this->table(Decisions::ITEMS)], 'i.decision_id = d.decision_id', [
            'won' => new Expression("SUM(i.stage NOT IN ('bidding', 'lost', 'void'))"),
            'spend' => new Expression('COALESCE(SUM(i.buy_cost), 0)'),
        ])->joinLeft(['s' => $this->table(Sales::TABLE)], 's.item_id = i.item_id', [
            'sold' => new Expression('COUNT(s.sale_id)'),
            'profit' => new Expression('COALESCE(SUM(s.realised_profit), 0)'),
        ])->where('d.pot_id = ?', $potId)->group('d.category')->order('candidates DESC'));
    }

    public function reasons(int $potId): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchPairs($db->select()->from($this->table(Decisions::TABLE),
            ['reason', new Expression('COUNT(*)')])->where('pot_id = ?', $potId)->group('reason')
            ->order(new Expression('COUNT(*) DESC')));
    }

    /** Worst ten settled buys by realised profit, then the worst ten skips that would have lost most. */
    public function worst(int $potId): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchAll($db->select()->from(['s' => $this->table(Sales::TABLE)],
            ['realised_profit', 'cautious_profit', 'error_percent', 'method', 'days_to_sell'])
            ->join(['i' => $this->table(Decisions::ITEMS)], 'i.item_id = s.item_id', ['stage', 'auction_result', 'buy_cost'])
            ->join(['d' => $this->table(Decisions::TABLE)], 'd.decision_id = i.decision_id',
                ['decision_id', 'title', 'category', 'condition_grade', 'predicted_resale', 'comp_count'])
            ->where('s.pot_id = ?', $potId)->order('s.realised_profit ASC')->limit(10));
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }
}
