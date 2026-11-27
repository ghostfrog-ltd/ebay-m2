<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model;

use GhostFrog\Ebay\Api\ListingOutcomesInterface;
use GhostFrog\Ebay\Api\PricingRulesInterface;
use GhostFrog\Ebay\Api\SoldComparablesInterface;
use GhostFrog\EbayShadow\Model\Config\Settings;
use GhostFrog\EbayShadow\Model\Engine\AuctionOutcome;
use GhostFrog\EbayShadow\Model\Engine\ForwardSales;
use GhostFrog\EbayShadow\Model\Engine\Lifecycle;
use GhostFrog\EbayShadow\Model\Engine\Pricer;
use Magento\Framework\App\ResourceConnection;
use Throwable;

/**
 * Moves shadow items forward in simulated time. Each step reads the decision's own settings snapshot.
 *
 * 1. Bidding items whose auction has ended are settled against core's verified outcome: won, lost or void.
 *    The reservation is released and, when won, the buy cost is debited.
 * 2. Won items move inbound > received > listed as their dates pass.
 * 3. A listed item's sale is resolved by the forward-sales check, which fixes its sold, delivered, payout
 *    and banked times. A stale item waits for its hold period and is then marked down or written off.
 * 4. On reaching paid out, the net proceeds are credited to the pot.
 *
 * Every write is guarded by the item's current stage and every cash movement has its own key, so running
 * this twice changes nothing the second time.
 */
class Advance
{
    private const ITEM_COLUMNS = [
        'item_id', 'decision_id', 'pot_id', 'listing_id', 'stage', 'reserved', 'buy_cost', 'stale',
        'bought_at', 'received_at', 'listed_at', 'sold_at', 'paid_out_at', 'banked_at',
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Settings $settings,
        private readonly Cash $cash,
        private readonly ListingOutcomesInterface $outcomes,
        private readonly SoldComparablesInterface $comparables,
        private readonly PricingRulesInterface $pricing
    ) {
    }

    /** @return array<string, int> Counts of what moved. */
    public function run(?int $now = null): array
    {
        $now ??= time();
        $counts = ['won' => 0, 'lost' => 0, 'void' => 0, 'sales' => 0, 'stale' => 0, 'moved' => 0, 'paid_out' => 0];
        if (!$this->settings->isActive()) {
            return $counts;
        }
        $this->settleAuctions($now, $counts);
        foreach ($this->items(Lifecycle::ACTIVE, ['bidding']) as $item) {
            $this->step($item, $now, $counts);
        }
        return $counts;
    }

    private function settleAuctions(int $now, array &$counts): void
    {
        $db = $this->resource->getConnection();
        $rows = $db->fetchAll($db->select()
            ->from(['i' => $this->table(Decisions::ITEMS)], self::ITEM_COLUMNS)
            ->join(['d' => $this->table(Decisions::TABLE)], 'd.decision_id = i.decision_id',
                ['max_bid', 'auction_end_at', 'settings_snapshot', 'evidence'])
            ->where('i.stage = ?', 'bidding')
            ->where('d.auction_end_at <= ?', gmdate('Y-m-d H:i:s', $now)));
        if ($rows === []) {
            return;
        }
        $outcomes = $this->outcomes->forListings(array_column($rows, 'listing_id'));
        foreach ($rows as $item) {
            $settings = json_decode((string)$item['settings_snapshot'], true);
            $evidence = json_decode((string)$item['evidence'], true);
            $result = AuctionOutcome::resolve((float)$item['max_bid'], $outcomes[(int)$item['listing_id']] ?? null,
                (string)$item['auction_end_at'], $now, (int)$settings['general/void_after_days']);
            if ($result['result'] === AuctionOutcome::PENDING) {
                continue;
            }
            $this->transaction(function () use ($item, $settings, $evidence, $result, $now, &$counts): void {
                $this->applyAuction($item, $settings, $evidence, $result, $now, $counts);
            });
        }
    }

    private function applyAuction(array $item, array $settings, array $evidence, array $result, int $now,
        array &$counts): void
    {
        $db = $this->resource->getConnection();
        $itemId = (int)$item['item_id'];
        $potId = (int)$item['pot_id'];
        $endAt = (string)$item['auction_end_at'];
        $fields = ['auction_result' => $result['reason'], 'final_price' => $result['final_price']];
        if ($result['result'] === AuctionOutcome::WON) {
            $paid = (float)$result['price'];
            $costs = $evidence['costs'] ?? [];
            $buyCost = round($paid + $this->pricing->buyerProtection($paid) + (float)($costs['inbound_postage'] ?? 0), 2);
            $bought = strtotime($endAt . ' UTC');
            $plan = Lifecycle::plan($settings, $bought, (float)($evidence['days_to_sell'] ?? 0));
            $fields += [
                'stage' => 'inbound',
                'price_paid' => $paid,
                'buy_cost' => $buyCost,
                'bought_at' => $endAt,
                'received_at' => self::utc($plan['received']),
                'listed_at' => self::utc($plan['listed']),
            ];
            foreach (['received', 'listed', 'sold', 'delivered', 'paid_out', 'banked'] as $stage) {
                $fields["planned_{$stage}_at"] = self::utc($plan[$stage]);
            }
        } else {
            $fields += ['stage' => $result['result'], 'closed_at' => $endAt];
        }
        if ($db->update($this->table(Decisions::ITEMS), $fields, ['item_id = ?' => $itemId, 'stage = ?' => 'bidding']) !== 1) {
            return;
        }
        $this->cash->record($potId, Cash::BID_RELEASE, (float)$item['reserved'], "release:$itemId", $endAt, $itemId,
            'Max bid reservation released: ' . $result['reason']);
        if ($result['result'] === AuctionOutcome::WON) {
            $this->cash->record($potId, Cash::BUY_DEBIT, -$fields['buy_cost'], "buy:$itemId", $endAt, $itemId,
                sprintf('Won at £%.2f (%s)', $fields['price_paid'], $result['reason']));
        }
        ++$counts[$result['result']];
    }

    private function step(array $item, int $now, array &$counts): void
    {
        $itemId = (int)$item['item_id'];
        $settings = json_decode((string)$item['settings_snapshot'], true);
        if ($item['stage'] === 'listed' && $item['sold_at'] === null) {
            $this->resolveSale($item, $settings, $now, $counts);
            $item = $this->item($itemId) + $item;
            if ($item['stage'] === 'written_off') {
                return;
            }
        }

        $times = [];
        foreach (['received_at', 'listed_at', 'sold_at', 'paid_out_at', 'banked_at'] as $field) {
            $times[$field] = $item[$field] === null ? null : strtotime($item[$field] . ' UTC');
        }
        $stage = Lifecycle::stageAt($times, $now);
        $order = array_flip(Lifecycle::STAGES);
        if ($order[$stage] <= $order[$item['stage']]) {
            return;
        }
        $this->transaction(function () use ($item, $itemId, $stage, &$counts): void {
            $db = $this->resource->getConnection();
            if ($db->update($this->table(Decisions::ITEMS), ['stage' => $stage],
                ['item_id = ?' => $itemId, 'stage = ?' => $item['stage']]) !== 1) {
                return;
            }
            ++$counts['moved'];
            if (in_array($stage, ['paid_out', 'banked'], true)) {
                $net = (float)$db->fetchOne($db->select()->from($this->table(Sales::TABLE), ['net_proceeds'])
                    ->where('item_id = ?', $itemId)->where('part_key = ?', 'whole'));
                if ($this->cash->record((int)$item['pot_id'], Cash::PAYOUT_CREDIT, $net, "payout:$itemId",
                    (string)$item['paid_out_at'], $itemId, 'eBay payout')) {
                    ++$counts['paid_out'];
                }
            }
        });
    }

    private function resolveSale(array $item, array $settings, int $now, array &$counts): void
    {
        $decision = $this->decision((int)$item['decision_id']);
        $decidedAt = strtotime($decision['decided_at'] . ' UTC');
        $grade = (string)$decision['condition_grade'];
        $sales = $this->comparables->sales((string)$decision['model_key'], $grade,
            gmdate('Y-m-d H:i:s', $decidedAt + 1), gmdate('Y-m-d H:i:s', $now));
        $gradeRisk = Pricer::gradeRisk($settings, $grade);
        $result = ForwardSales::resolve($settings, (float)$decision['predicted_resale'], $gradeRisk, $decidedAt,
            strtotime($item['bought_at'] . ' UTC'), strtotime($item['listed_at'] . ' UTC'), $now, $sales);
        if ($result === null) {
            return;
        }
        $itemId = (int)$item['item_id'];
        $db = $this->resource->getConnection();
        if ($result['method'] === null) {
            if (!(int)$item['stale'] && $db->update($this->table(Decisions::ITEMS), ['stale' => 1],
                ['item_id = ?' => $itemId, 'stale = ?' => 0]) === 1) {
                ++$counts['stale'];
            }
            return;
        }

        $evidence = json_decode((string)$decision['evidence'], true);
        $costs = $evidence['costs'] ?? [];
        $pricer = new Pricer($settings, fn(float $price): float => $this->pricing->buyerProtection($price),
            (float)($costs['selling_fee_percent'] ?? 0), (float)($costs['inbound_postage'] ?? 0),
            (float)($costs['outbound_postage'] ?? 0), $gradeRisk);
        $writeOff = $result['method'] === ForwardSales::WRITE_OFF;
        $net = $writeOff ? ['fees' => 0.0, 'postage' => 0.0, 'packaging' => 0.0, 'returns_cost' => 0.0, 'net' => 0.0]
            : $pricer->saleNet($result['sell_price']);
        $buyCost = (float)$item['buy_cost'];
        $predicted = (float)$decision['predicted_resale'];
        $soldAt = (int)$result['sold_at'];
        $after = Lifecycle::afterSale($settings, $soldAt);

        $this->transaction(function () use ($db, $item, $itemId, $decision, $result, $net, $buyCost, $predicted,
            $soldAt, $after, $writeOff, $settings, $now, &$counts): void {
            $saved = $db->insertOnDuplicate($this->table(Sales::TABLE), [
                'item_id' => $itemId,
                'part_key' => 'whole',
                'pot_id' => (int)$item['pot_id'],
                'method' => $result['method'],
                'predicted_resale' => $predicted,
                'sell_price' => $result['sell_price'],
                'fees' => $net['fees'],
                'postage' => $net['postage'],
                'packaging' => $net['packaging'],
                'returns_cost' => $net['returns_cost'],
                'net_proceeds' => $net['net'],
                'buy_cost' => $buyCost,
                'realised_profit' => round($net['net'] - $buyCost, 2),
                'cautious_profit' => $decision['cautious_profit'],
                'error_percent' => $predicted > 0 ? round(100 * ($result['sell_price'] - $predicted) / $predicted, 2) : null,
                'days_to_sell' => $result['days_to_sell'],
                'forward_sales' => count($result['forward_sales']),
                'evidence' => json_encode([
                    'statistic' => $settings['sell/statistic'],
                    'window_end' => self::utc($result['window_end']),
                    'grade_risk_percent' => Pricer::gradeRisk($settings, (string)$decision['condition_grade']),
                    'forward_sales' => array_map(static fn(array $s): array =>
                        [$s['listing_id'], $s['final_price'], $s['end_time']], $result['forward_sales']),
                ], JSON_THROW_ON_ERROR),
                'resolved_at' => gmdate('Y-m-d H:i:s', $now),
            ], ['item_id']);
            if ($saved !== 1) {
                return;
            }
            $fields = $writeOff
                ? ['stage' => 'written_off', 'stale' => 1, 'closed_at' => self::utc($soldAt), 'sold_at' => null]
                : ['stale' => (int)$result['stale'], 'sold_at' => self::utc($soldAt),
                    'delivered_at' => self::utc($after['delivered']), 'paid_out_at' => self::utc($after['paid_out']),
                    'banked_at' => self::utc($after['banked'])];
            $db->update($this->table(Decisions::ITEMS), $fields, ['item_id = ?' => $itemId, 'stage = ?' => 'listed']);
            if ($writeOff) {
                $this->cash->record((int)$item['pot_id'], Cash::WRITE_OFF, 0.0, "writeoff:$itemId", self::utc($soldAt),
                    $itemId, sprintf('Written off, cost £%.2f', $buyCost));
            }
            ++$counts['sales'];
        });
    }

    /** @return iterable<array> Items in $stages, joined to their decision's settings snapshot. */
    private function items(array $stages, array $except = []): iterable
    {
        $db = $this->resource->getConnection();
        $select = $db->select()
            ->from(['i' => $this->table(Decisions::ITEMS)], self::ITEM_COLUMNS)
            ->join(['d' => $this->table(Decisions::TABLE)], 'd.decision_id = i.decision_id', ['settings_snapshot'])
            ->where('i.stage IN (?)', array_values(array_diff($stages, $except)))
            ->where('i.item_id > :last')->order('i.item_id ASC')->limit(200);
        $last = 0;
        while ($rows = $db->fetchAll($select, ['last' => $last])) {
            yield from $rows;
            $last = (int)end($rows)['item_id'];
        }
    }

    private function item(int $itemId): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchRow($db->select()->from($this->table(Decisions::ITEMS), self::ITEM_COLUMNS)
            ->where('item_id = ?', $itemId)) ?: [];
    }

    private function decision(int $decisionId): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchRow($db->select()->from($this->table(Decisions::TABLE), [
            'decided_at', 'model_key', 'condition_grade', 'predicted_resale', 'cautious_profit', 'evidence',
        ])->where('decision_id = ?', $decisionId));
    }

    private function transaction(callable $work): void
    {
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $work();
            $db->commit();
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }

    private static function utc(int $time): string
    {
        return gmdate('Y-m-d H:i:s', $time);
    }
}
