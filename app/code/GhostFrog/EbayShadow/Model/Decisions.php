<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model;

use GhostFrog\Ebay\Api\AuctionCandidatesInterface;
use GhostFrog\Ebay\Api\PricingRulesInterface;
use GhostFrog\Ebay\Api\SoldComparablesInterface;
use GhostFrog\EbayShadow\Model\Config\Settings;
use GhostFrog\EbayShadow\Model\Engine\BidIncrements;
use GhostFrog\EbayShadow\Model\Engine\Lifecycle;
use GhostFrog\EbayShadow\Model\Engine\Pricer;
use GhostFrog\EbayShadow\Model\Engine\Selector;
use GhostFrog\EbayShadow\Model\Engine\Statistics;
use GhostFrog\EbayShadow\Model\Extraction\Extractor;
use GhostFrog\EbayShadow\Model\Extraction\Schema;
use GhostFrog\EbayShadow\Model\Extraction\Store as Extractions;
use Magento\Framework\App\ResourceConnection;
use Throwable;

/**
 * One decision run: for every running pot, evaluate the live auctions that end inside the decision window,
 * place shadow max bids greedily by score, and log every candidate with its outcome and reason.
 *
 * Each pot and listing has one decision row. A skipped candidate is re-evaluated on later runs until its
 * auction ends, and its row keeps the latest evaluation. A bought decision is final. The reservation for a
 * max bid is a cash movement keyed by the decision, so a repeated run never reserves twice.
 */
class Decisions
{
    public const TABLE = 'ghostfrog_ebayshadow_decision';
    public const ITEMS = 'ghostfrog_ebayshadow_item';
    public const RUNS = 'ghostfrog_ebayshadow_run';
    private const LOOKBACK_DAYS = 365;

    private array $salesCache = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Settings $settings,
        private readonly Pots $pots,
        private readonly Cash $cash,
        private readonly AuctionCandidatesInterface $candidates,
        private readonly SoldComparablesInterface $comparables,
        private readonly PricingRulesInterface $pricing,
        private readonly Extractions $extractions,
        private readonly Extractor $extractor,
        private readonly Alerts $alerts
    ) {
    }

    /** @return array<int, array{candidates: int, bought: int, no_cash: int, free_cash: float}> Per pot. */
    public function run(?int $potId = null, ?int $now = null): array
    {
        if (!$this->settings->isActive()) {
            return [];
        }
        $now ??= time();
        $nowUtc = gmdate('Y-m-d H:i:s', $now);
        $this->salesCache = [];
        $global = $this->settings->resolve();
        $listings = $this->candidates->endingBetween($nowUtc,
            gmdate('Y-m-d H:i:s', $now + $global['general/decision_window_minutes'] * 60));

        $summary = [];
        foreach ($this->pots->running($nowUtc, $potId) as $pot) {
            $summary[(int)$pot['pot_id']] = $this->runPot($pot, $listings, $now);
        }
        return $summary;
    }

    private function runPot(array $pot, array $listings, int $now): array
    {
        $potId = (int)$pot['pot_id'];
        $capital = (float)$pot['starting_capital'];
        $this->cash->ensureCapital($potId, $capital, (string)$pot['start_date']);
        $settings = $this->settings->resolve($pot['overrides'] ?? null);
        $snapshot = $settings + ['pot/pot_id' => $potId, 'pot/starting_capital' => $capital,
            'pot/name' => (string)$pot['name']];

        $db = $this->resource->getConnection();
        $existing = $listings === [] ? [] : $db->fetchAssoc($db->select()
            ->from($this->resource->getTableName(self::TABLE), ['listing_id', 'decision_id', 'outcome', 'evaluations'])
            ->where('pot_id = ?', $potId)->where('listing_id IN (?)', array_keys($listings)));
        $model = $this->extractor->model($settings);
        $extractions = $this->extractions->forListings(array_keys($listings), $model,
            $settings['llm/prompt_version'] ?: 'v1');

        $evaluated = [];
        foreach ($listings as $id => $listing) {
            if (($existing[$id]['outcome'] ?? null) === Selector::BOUGHT) {
                continue;
            }
            $evaluated[$id] = $this->evaluate($listing, $settings, $capital, $now, $extractions[$id] ?? null);
        }

        $free = $this->cash->balance($potId);
        $passing = [];
        foreach ($evaluated as $id => $evaluation) {
            if ($evaluation['reason'] === null) {
                $passing[] = ['key' => $id, 'category' => $listings[$id]['source'], 'score' => $evaluation['score'],
                    'reserve' => $evaluation['reserve']];
            }
        }
        $selected = Selector::select($passing, $free, $capital, $settings['capital/max_item_percent'],
            $settings['capital/max_category_percent'], $this->committedByCategory($potId));

        $counts = ['candidates' => count($evaluated), 'bought' => 0, 'no_cash' => 0, 'free_cash' => $free];
        $bought = [];
        $db->beginTransaction();
        try {
            $db->insert($this->resource->getTableName(self::RUNS), [
                'pot_id' => $potId, 'run_at' => gmdate('Y-m-d H:i:s', $now), 'free_cash' => $free,
            ]);
            $runId = (int)$db->lastInsertId();
            foreach ($evaluated as $id => $evaluation) {
                $choice = $selected[$id] ?? ['outcome' => Selector::SKIPPED, 'reason' => $evaluation['reason']];
                $decisionId = $this->saveDecision($potId, $runId, $listings[$id], $evaluation, $choice, $snapshot,
                    $existing[$id] ?? null, $now);
                if ($choice['reason'] === 'no_cash') {
                    ++$counts['no_cash'];
                }
                if ($choice['outcome'] === Selector::BOUGHT && $this->openItem($potId, $decisionId, $listings[$id],
                    $evaluation, $now)) {
                    ++$counts['bought'];
                    $bought[] = $listings[$id] + $evaluation;
                }
            }
            $db->update($this->resource->getTableName(self::RUNS), [
                'candidates' => $counts['candidates'], 'bought' => $counts['bought'], 'no_cash' => $counts['no_cash'],
            ], ['run_id = ?' => $runId]);
            $db->commit();
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        }

        if ($settings['alerts/slack_on_buy']) {
            foreach ($bought as $buy) {
                $this->alerts->buy((string)$pot['name'], $buy);
            }
        }
        return $counts;
    }

    /**
     * Applies shadow's evidence checks and prices one candidate. A non-null reason means skipped.
     *
     * @return array{reason: ?string, ...}
     */
    public function evaluate(array $listing, array $settings, float $capital, int $now, ?array $extraction): array
    {
        $grade = (string)$listing['condition_grade'];
        $confidences = array_filter([$listing['text_confidence'], $listing['visual_confidence']],
            static fn($value) => $value !== null);
        $confidence = $confidences === [] ? null : min($confidences);
        $priceAt = strtotime(($listing['last_price_checked_at'] ?: $listing['last_seen_at']) . ' UTC') ?: 0;
        $evaluation = [
            'reason' => null,
            'grade_confidence' => $confidence,
            'price_age_hours' => round(($now - $priceAt) / 3600, 2),
            'extraction' => $extraction === null ? null : [
                'status' => $extraction['status'], 'fields' => $extraction['fields'],
                'model' => $extraction['model'], 'prompt_version' => $extraction['prompt_version'],
                'extracted_at' => $extraction['extracted_at'],
            ],
            'comp_count' => 0, 'prior_sales' => [], 'predicted_resale' => null, 'expected_price' => null,
            'max_bid' => null, 'cautious_profit' => null, 'roi_percent' => null, 'cycle_days' => null,
            'score' => null, 'reserve' => null, 'days_to_sell' => null, 'costs' => null,
        ];
        $skip = static fn(string $reason): array => ['reason' => $reason] + $evaluation;

        $categories = $settings['general/categories'];
        if ($categories !== [] && !in_array($listing['source'], $categories, true)) {
            return $skip('category_disabled');
        }
        if (!in_array($grade, ['A', 'B', 'C'], true)) {
            // Faulty listings are valued only against faulty sales; shadow does not trade them in this build.
            return $skip('faulty_excluded');
        }
        if ($evaluation['price_age_hours'] > $settings['evidence/max_price_age_hours']) {
            return $skip('stale_price');
        }
        $minConfidence = $settings['evidence/min_grade_confidence'];
        if ($minConfidence > 0 && ($confidence === null || $confidence < $minConfidence)) {
            return $skip('low_confidence');
        }
        if ($listing['text_review'] || $listing['visual_review']) {
            return $skip('review_needed');
        }
        if ($settings['evidence/require_extraction'] && $settings['llm/enabled']) {
            if ($extraction === null || ($extraction['status'] === Extractions::ERROR
                && (int)$extraction['attempts'] < Extractions::MAX_ATTEMPTS)) {
                return $skip('extraction_pending');
            }
            $fields = $extraction['fields'];
            if ($extraction['status'] !== Extractions::OK || !is_array($fields)
                || $fields['confidence'] < $settings['llm/min_confidence']) {
                return $skip('review_needed');
            }
            if (Schema::risk($fields) !== null) {
                return $skip('extracted_risk');
            }
        }

        // Only sales verified before the decision value the item.
        $sales = $this->priorSales((string)$listing['model_key'], $grade, $now);
        $evaluation['comp_count'] = count($sales);
        $evaluation['prior_sales'] = array_map(static fn(array $sale): array =>
            [$sale['listing_id'], $sale['final_price'], $sale['end_time']], $sales);
        if (count($sales) < max(1, $settings['evidence/min_comparables'])) {
            return $skip('few_comparables');
        }
        $predicted = Statistics::of($settings['sell/statistic'], array_column($sales, 'final_price'));
        $window = $settings['sell/forward_window_days'];
        $recent = count(array_filter($sales,
            static fn(array $sale): bool => strtotime($sale['end_time'] . ' UTC') >= $now - $window * 86400));
        $daysToSell = Pricer::daysToSell($recent, $window, $settings['sell/max_days_to_sell']);

        $inbound = Pricer::inboundPostage((float)$listing['shipping'], $listing['inbound_postage']);
        $outbound = Pricer::outboundPostage($settings, (string)$listing['source'], (float)$listing['shipping'],
            $listing['inbound_postage']);
        $pricer = new Pricer($settings, fn(float $price): float => $this->pricing->buyerProtection($price),
            (float)$listing['selling_fee_percent'], $inbound, $outbound, Pricer::gradeRisk($settings, $grade));
        $price = (float)$listing['price_current'];
        $expected = (int)$listing['bids_count'] > 0 ? BidIncrements::next($price) : $price;
        $profit = $pricer->cautiousProfit($predicted, $expected);
        $cycle = Pricer::cycleDays($settings, $daysToSell);
        $evaluation = [
            'predicted_resale' => $predicted,
            'expected_price' => $expected,
            'cautious_profit' => $profit,
            'roi_percent' => $pricer->roiPercent($profit, $expected),
            'cycle_days' => $cycle,
            'days_to_sell' => $daysToSell,
            'score' => Pricer::score($profit, $pricer->buyCost($expected), $cycle),
            'costs' => [
                'inbound_postage' => $inbound,
                'outbound_postage' => $outbound,
                'selling_fee_percent' => (float)$listing['selling_fee_percent'],
                'grade_risk_percent' => $pricer->gradeRiskPercent(),
                'cautious_sell_price' => $pricer->cautiousSellPrice($predicted),
                'buy_cost_at_expected' => $pricer->buyCost($expected),
                'sales_in_window' => $recent,
            ] + $pricer->saleNet($pricer->cautiousSellPrice($predicted)),
        ] + $evaluation;
        $skip = static fn(string $reason): array => ['reason' => $reason] + $evaluation;

        if ($profit < $settings['capital/min_cautious_profit']) {
            return $skip('below_profit');
        }
        if ($evaluation['roi_percent'] < $settings['capital/min_roi_percent']) {
            return $skip('below_roi');
        }
        $itemCap = $capital * $settings['capital/max_item_percent'] / 100;
        if ($pricer->buyCost($expected) > $itemCap) {
            return $skip('item_cap');
        }
        $maxBid = $pricer->maxBid($predicted, $itemCap);
        if ($maxBid === null || $maxBid < $expected) {
            return $skip('below_profit');
        }
        $evaluation['max_bid'] = $maxBid;
        $evaluation['reserve'] = $pricer->buyCost($maxBid);
        return $evaluation;
    }

    private function priorSales(string $modelKey, string $grade, int $now): array
    {
        return $this->salesCache["$modelKey|$grade"] ??= $this->comparables->sales($modelKey, $grade,
            gmdate('Y-m-d H:i:s', $now - self::LOOKBACK_DAYS * 86400), gmdate('Y-m-d H:i:s', $now));
    }

    /** Cash committed to open items per category: the reservation while bidding, then the buy cost. */
    private function committedByCategory(int $potId): array
    {
        $db = $this->resource->getConnection();
        $rows = $db->fetchAll($db->select()->from($this->resource->getTableName(self::ITEMS),
            ['category', 'stage', 'reserved', 'buy_cost'])
            ->where('pot_id = ?', $potId)->where('stage IN (?)', Lifecycle::OPEN));
        $committed = [];
        foreach ($rows as $row) {
            $amount = $row['stage'] === 'bidding' ? (float)$row['reserved'] : (float)$row['buy_cost'];
            $committed[$row['category']] = ($committed[$row['category']] ?? 0.0) + $amount;
        }
        return $committed;
    }

    private function saveDecision(int $potId, int $runId, array $listing, array $evaluation, array $choice,
        array $snapshot, ?array $existing, int $now): int
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $evidence = [
            'listing' => $listing,
            'grade_confidence' => $evaluation['grade_confidence'],
            'price_age_hours' => $evaluation['price_age_hours'],
            'extraction' => $evaluation['extraction'],
            'prior_sales' => ['columns' => ['listing_id', 'final_price', 'end_time'], 'rows' => $evaluation['prior_sales']],
            'days_to_sell' => $evaluation['days_to_sell'],
            'costs' => $evaluation['costs'],
            'reserve' => $evaluation['reserve'],
            'evaluation_reason' => $evaluation['reason'],
        ];
        $row = [
            'pot_id' => $potId,
            'listing_id' => (int)$listing['listing_id'],
            'external_id' => $listing['external_id'],
            'title' => $listing['title'],
            'url' => $listing['url'],
            'category' => $listing['source'],
            'sale_type' => 'auction',
            'model_key' => $listing['model_key'],
            'condition_grade' => $listing['condition_grade'],
            'grade_confidence' => $evaluation['grade_confidence'],
            'comp_count' => $evaluation['comp_count'],
            'current_price' => $listing['price_current'],
            'expected_price' => $evaluation['expected_price'],
            'max_bid' => $evaluation['max_bid'],
            'predicted_resale' => $evaluation['predicted_resale'],
            'cautious_profit' => $evaluation['cautious_profit'],
            'roi_percent' => $evaluation['roi_percent'],
            'cycle_days' => $evaluation['cycle_days'],
            'score' => $evaluation['score'],
            'outcome' => $choice['outcome'],
            'reason' => $choice['reason'],
            'auction_end_at' => $listing['end_time'],
            'decided_at' => gmdate('Y-m-d H:i:s', $now),
            'run_id' => $runId,
            'settings_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        ];
        if ($existing === null) {
            $db->insert($table, $row);
            return (int)$db->lastInsertId();
        }
        // Only a skipped row is re-evaluated; the guard keeps a bought decision final.
        $db->update($table, $row + ['evaluations' => (int)$existing['evaluations'] + 1], [
            'decision_id = ?' => (int)$existing['decision_id'], 'outcome = ?' => Selector::SKIPPED,
        ]);
        return (int)$existing['decision_id'];
    }

    private function openItem(int $potId, int $decisionId, array $listing, array $evaluation, int $now): bool
    {
        $db = $this->resource->getConnection();
        $inserted = $db->insertOnDuplicate($this->resource->getTableName(self::ITEMS), [
            'decision_id' => $decisionId,
            'pot_id' => $potId,
            'listing_id' => (int)$listing['listing_id'],
            'category' => $listing['source'],
            'stage' => 'bidding',
            'reserved' => $evaluation['reserve'],
        ], ['decision_id']);
        if ($inserted !== 1) {
            return false;
        }
        $itemId = (int)$db->lastInsertId();
        $this->cash->record($potId, Cash::BID_RESERVE, -$evaluation['reserve'], "reserve:$decisionId",
            gmdate('Y-m-d H:i:s', $now), $itemId, sprintf('Max bid £%.2f', $evaluation['max_bid']));
        return true;
    }
}
