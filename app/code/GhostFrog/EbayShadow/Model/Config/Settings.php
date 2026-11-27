<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Effective shadow settings: Stores > Configuration > GhostFrog > Shadow Trading, with a pot's overrides on top.
 *
 * resolve() returns a plain typed array. Decisions store it as their settings snapshot, and every later step
 * for that decision reads the snapshot, never the live config, so changing a setting never rewrites results.
 */
class Settings
{
    public const SECTION = 'ghostfrog_ebayshadow';
    public const MODE_OFF = 'off';
    public const MODE_SHADOW = 'shadow';

    /** Setting key => type. Keys are group/field under the section. */
    public const FIELDS = [
        'general/mode' => 'string',
        'general/categories' => 'list',
        'general/decision_window_minutes' => 'int',
        'general/void_after_days' => 'int',
        'capital/default_capital' => 'float',
        'capital/max_item_percent' => 'float',
        'capital/max_category_percent' => 'float',
        'capital/min_cautious_profit' => 'float',
        'capital/min_roi_percent' => 'float',
        'evidence/min_grade_confidence' => 'float',
        'evidence/min_comparables' => 'int',
        'evidence/max_price_age_hours' => 'float',
        'evidence/cautious_discount_percent' => 'float',
        'evidence/grade_risk' => 'map',
        'evidence/require_extraction' => 'bool',
        'inbound/seller_dispatch_days' => 'float',
        'inbound/delivery_days' => 'map',
        'outbound/listing_days' => 'float',
        'outbound/dispatch_days' => 'float',
        'outbound/delivery_days' => 'float',
        'outbound/postage' => 'map',
        'outbound/category_bands' => 'map',
        'outbound/packaging' => 'float',
        'costs/per_order_fee' => 'float',
        'costs/payout_delay_days' => 'float',
        'costs/return_window_days' => 'float',
        'costs/returns_rate_percent' => 'float',
        'sell/forward_window_days' => 'int',
        'sell/statistic' => 'string',
        'sell/min_forward_sales' => 'int',
        'sell/max_days_to_sell' => 'float',
        'sell/max_hold_days' => 'float',
        'sell/stale_action' => 'string',
        'sell/stale_markdown_percent' => 'float',
        'llm/enabled' => 'bool',
        'llm/model' => 'string',
        'llm/prompt_version' => 'string',
        'llm/timeout' => 'int',
        'llm/min_confidence' => 'float',
        'llm/batch' => 'int',
        'alerts/slack_on_buy' => 'bool',
        'alerts/daily_summary' => 'bool',
    ];

    /** Settings a pot may override: risk and evidence. */
    public const POT_OVERRIDABLE = [
        'capital/max_item_percent', 'capital/max_category_percent', 'capital/min_cautious_profit',
        'capital/min_roi_percent', 'evidence/min_grade_confidence', 'evidence/min_comparables',
        'evidence/max_price_age_hours', 'evidence/cautious_discount_percent', 'evidence/grade_risk',
        'evidence/require_extraction',
    ];

    public function __construct(private readonly ScopeConfigInterface $config)
    {
    }

    public function mode(): string
    {
        return $this->raw('general/mode') === self::MODE_SHADOW ? self::MODE_SHADOW : self::MODE_OFF;
    }

    public function isActive(): bool
    {
        return $this->mode() === self::MODE_SHADOW;
    }

    /** @param array<string, mixed>|string|null $overrides Pot overrides, as stored. */
    public function resolve(array|string|null $overrides = null): array
    {
        if (is_string($overrides)) {
            $overrides = json_decode($overrides, true);
        }
        $overrides = is_array($overrides) ? $overrides : [];
        $settings = [];
        foreach (self::FIELDS as $key => $type) {
            $value = $this->raw($key);
            if (in_array($key, self::POT_OVERRIDABLE, true) && array_key_exists($key, $overrides)
                && $overrides[$key] !== null && $overrides[$key] !== ''
            ) {
                $value = $overrides[$key];
            }
            $settings[$key] = self::cast($value, $type);
        }
        // There is no live mode in this build: anything but "shadow" is off.
        $settings['general/mode'] = $settings['general/mode'] === self::MODE_SHADOW ? self::MODE_SHADOW : self::MODE_OFF;
        return $settings;
    }

    public static function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'int' => (int)$value,
            'float' => (float)$value,
            'bool' => (bool)(int)$value,
            'list' => array_values(array_filter(array_map('trim', is_array($value) ? $value : explode(',', (string)$value)),
                static fn(string $item): bool => $item !== '')),
            'map' => is_array($value) ? $value : self::map((string)$value),
            default => trim((string)$value),
        };
    }

    /** Parses "key:value" pairs separated by commas or new lines. */
    public static function map(string $value): array
    {
        $map = [];
        foreach (preg_split('/[\r\n,]+/', $value) as $pair) {
            [$key, $item] = array_pad(array_map('trim', explode(':', $pair, 2)), 2, '');
            if ($key !== '' && $item !== '') {
                $map[$key] = is_numeric($item) ? (float)$item : $item;
            }
        }
        return $map;
    }

    private function raw(string $key): mixed
    {
        return $this->config->getValue(self::SECTION . '/' . $key);
    }
}
