<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Block\Adminhtml;

use GhostFrog\Ebay\Model\Opportunities\ListingUrl;
use GhostFrog\EbayShadow\Model\Decisions;
use GhostFrog\EbayShadow\Model\Sales;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ResourceConnection;

/** A decision with its full evidence and settings snapshot, and its item, sale and cash movements. */
class DecisionView extends Template
{
    private ?array $decision = null;

    public function __construct(Context $context, private readonly ResourceConnection $resource, array $data = [])
    {
        parent::__construct($context, $data);
    }

    public function decision(): ?array
    {
        if ($this->decision === null) {
            $db = $this->resource->getConnection();
            $row = $db->fetchRow($db->select()->from(['d' => $this->resource->getTableName(Decisions::TABLE)])
                ->joinLeft(['p' => $this->resource->getTableName('ghostfrog_ebayshadow_pot')], 'p.pot_id = d.pot_id',
                    ['pot_name' => 'name'])
                ->where('d.decision_id = ?', (int)$this->getRequest()->getParam('id')));
            $this->decision = $row ?: [];
        }
        return $this->decision ?: null;
    }

    public function item(): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->resource->getTableName(Decisions::ITEMS))
            ->where('decision_id = ?', (int)($this->decision()['decision_id'] ?? 0)));
        return $row ?: null;
    }

    public function sale(?array $item): ?array
    {
        if ($item === null) {
            return null;
        }
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->resource->getTableName(Sales::TABLE))
            ->where('item_id = ?', (int)$item['item_id']));
        return $row ?: null;
    }

    public function movements(?array $item): array
    {
        if ($item === null) {
            return [];
        }
        $db = $this->resource->getConnection();
        return $db->fetchAll($db->select()->from($this->resource->getTableName('ghostfrog_ebayshadow_cash_movement'),
            ['occurred_at', 'type', 'amount', 'idempotency_key', 'note'])
            ->where('item_id = ?', (int)$item['item_id'])->order('movement_id ASC'));
    }

    public function listingUrl(array $decision): ?string
    {
        return ListingUrl::resolve($decision['url'] ?? null, (string)$decision['external_id']);
    }

    /**
     * A few plain sentences on why the decision came out as it did, built from the decision's own evidence and
     * settings snapshot, so it describes what was known at the time.
     *
     * @return string[]
     */
    public function explanation(array $decision): array
    {
        $settings = json_decode((string)$decision['settings_snapshot'], true) ?: [];
        $evidence = json_decode((string)$decision['evidence'], true) ?: [];
        $listing = $evidence['listing'] ?? [];
        $costs = $evidence['costs'] ?? [];
        $money = static fn($value): string => ((float)$value < 0 ? '-£' : '£') . number_format(abs((float)$value), 2);
        $model = (string)$decision['model_key'];
        $grade = (string)$decision['condition_grade'];
        $reason = (string)$decision['reason'];

        if ($decision['outcome'] === 'bought') {
            $statistic = ($settings['sell/statistic'] ?? '') === 'median' ? 'median' : 'lower quartile';
            $lines = [
                sprintf('Shadow placed a pretend max bid of %s. In the past year %d verified %s sales in grade %s '
                    . 'had a %s price of %s, so that is what it expects to resell for.',
                    $money($decision['max_bid']), $decision['comp_count'], $model, $grade, $statistic,
                    $money($decision['predicted_resale'])),
                sprintf('To be safe it knocks off %s%% caution and %s%% grade risk (selling at about %s), then fees of '
                    . '%s, postage of %s and packaging of %s. Bought at %s, that still leaves %s profit, a return of %s%%.',
                    self::number($settings['evidence/cautious_discount_percent'] ?? 0),
                    self::number($costs['grade_risk_percent'] ?? 0), $money($costs['cautious_sell_price'] ?? 0),
                    $money($costs['fees'] ?? 0), $money($costs['postage'] ?? 0), $money($costs['packaging'] ?? 0),
                    $money($decision['expected_price']), $money($decision['cautious_profit']),
                    self::number((float)$decision['roi_percent'])),
            ];
            $capital = (float)($settings['pot/starting_capital'] ?? 0);
            $itemCap = $capital * (float)($settings['capital/max_item_percent'] ?? 100) / 100;
            $capped = $itemCap > 0 && (float)($evidence['reserve'] ?? 0) >= $itemCap - 0.01;
            $lines[] = $capped
                ? sprintf('The max bid is held down by the pot limit: one item may tie up at most %s%% of the %s pot '
                    . '(%s including fees and postage).', self::number($settings['capital/max_item_percent']),
                    $money($capital), $money($itemCap))
                : sprintf('%s is the most it can pay and still make at least %s and a %s%% return.',
                    $money($decision['max_bid']), $money($settings['capital/min_cautious_profit'] ?? 0),
                    self::number($settings['capital/min_roi_percent'] ?? 0));
            $recent = (int)($costs['sales_in_window'] ?? 0);
            $window = (int)($settings['sell/forward_window_days'] ?? 30);
            $lines[] = sprintf('It expects the money back in about %s days, %s of them waiting to sell (%s).',
                self::number((float)$decision['cycle_days']), self::number((float)($evidence['days_to_sell'] ?? 0)),
                $recent === 0 ? "none sold in the last $window days, so it assumes the slowest case"
                    : "$recent sold in the last $window days");
            return $lines;
        }

        $why = match ($reason) {
            'no_cash' => sprintf('It passed every check and was worth about %s profit, but the pot did not have enough '
                . 'free cash at the time. This counts as missed profit on the dashboard.', $money($decision['cautious_profit'])),
            'below_profit' => match (true) {
                $decision['cautious_profit'] === null => 'It would not make enough profit.',
                (float)$decision['cautious_profit'] < 0 => sprintf('At %s it would lose %s once fees, postage and '
                    . 'the safety margins are taken off.', $money($decision['expected_price']),
                    $money(abs((float)$decision['cautious_profit']))),
                default => sprintf('At %s it would only make %s after fees, postage and the safety margins, below '
                    . 'the %s minimum.', $money($decision['expected_price']), $money($decision['cautious_profit']),
                    $money($settings['capital/min_cautious_profit'] ?? 0)),
            },
            'below_roi' => sprintf('The return of %s%% is below the %s%% minimum.',
                self::number((float)$decision['roi_percent']), self::number($settings['capital/min_roi_percent'] ?? 0)),
            'item_cap' => sprintf('Buying it would tie up more than %s%% of the pot, the limit for one item.',
                self::number($settings['capital/max_item_percent'] ?? 0)),
            'category_cap' => 'The pot already has as much as it allows tied up in this category.',
            'stale_price' => sprintf('Core had not checked the price for %s hours, more than the %s-hour limit, so the '
                . 'price could not be trusted.', self::number((float)($evidence['price_age_hours'] ?? 0)),
                self::number($settings['evidence/max_price_age_hours'] ?? 0)),
            'low_confidence' => ($evidence['grade_confidence'] ?? null) === null
                ? 'Core has no text or photo condition check for this listing yet, so its grade is unconfirmed.'
                : sprintf('Core\'s condition checks were only %s sure of the grade, below the %s minimum.',
                    self::number((float)$evidence['grade_confidence']), self::number($settings['evidence/min_grade_confidence'] ?? 0)),
            'few_comparables' => sprintf('Only %d verified sales of this model in grade %s, fewer than the %d needed '
                . 'to trust a resale price.', $decision['comp_count'], $grade, $settings['evidence/min_comparables'] ?? 0),
            'faulty_excluded' => 'Core graded it as faulty (grade F). Shadow does not trade faulty items in this build.',
            'extraction_pending' => 'Qwen had not yet read the description, so Shadow waited.',
            'extracted_risk' => 'Qwen found problems in the description: '
                . self::risks($evidence['extraction']['fields'] ?? []) . '.',
            'review_needed' => 'The description check was unclear or unsure, so it needs a human look.',
            'category_disabled' => 'This category is switched off in Shadow settings.',
            default => 'Skipped: ' . $reason . '.',
        };
        return ['Shadow skipped this listing. ' . $why];
    }

    private static function risks(array $fields): string
    {
        $risks = [];
        if (!empty($fields['locked_or_blacklisted'])) {
            $risks[] = 'locked or blacklisted';
        }
        if (!empty($fields['spares_or_repairs'])) {
            $risks[] = 'sold for spares or repair';
        }
        if (!empty($fields['untested'])) {
            $risks[] = 'untested';
        }
        foreach (array_slice($fields['faults'] ?? [], 0, 4) as $fault) {
            $risks[] = mb_strtolower(rtrim((string)$fault, '.'));
        }
        return $risks === [] ? 'unspecified' : implode('; ', $risks);
    }

    private static function number(float|int|string $value): string
    {
        return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
    }

    public function pretty(?string $json): string
    {
        $value = json_decode((string)$json, true);
        return (string)json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
