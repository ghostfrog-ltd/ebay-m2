<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Test\Unit\Model\Engine;

/** Settings snapshot matching the module's config.xml defaults. */
final class SettingsFixture
{
    public static function defaults(array $overrides = []): array
    {
        return $overrides + [
            'general/mode' => 'shadow',
            'general/categories' => [],
            'general/decision_window_minutes' => 60,
            'general/void_after_days' => 7,
            'capital/default_capital' => 150.0,
            'capital/max_item_percent' => 60.0,
            'capital/max_category_percent' => 100.0,
            'capital/min_cautious_profit' => 10.0,
            'capital/min_roi_percent' => 10.0,
            'evidence/min_grade_confidence' => 0.7,
            'evidence/min_comparables' => 15,
            'evidence/max_price_age_hours' => 1.0,
            'evidence/cautious_discount_percent' => 10.0,
            'evidence/grade_risk' => ['A' => 3.0, 'B' => 5.0, 'C' => 10.0],
            'evidence/require_extraction' => true,
            'inbound/seller_dispatch_days' => 2.0,
            'inbound/delivery_days' => ['default' => 2.0],
            'outbound/listing_days' => 2.0,
            'outbound/dispatch_days' => 1.0,
            'outbound/delivery_days' => 2.0,
            'outbound/postage' => ['default' => 'core', 'small' => 3.5],
            'outbound/category_bands' => ['ebay-apple' => 'small'],
            'outbound/packaging' => 2.5,
            'costs/per_order_fee' => 0.0,
            'costs/payout_delay_days' => 2.0,
            'costs/return_window_days' => 30.0,
            'costs/returns_rate_percent' => 0.0,
            'sell/forward_window_days' => 30,
            'sell/statistic' => 'lower_quartile',
            'sell/min_forward_sales' => 3,
            'sell/max_days_to_sell' => 30.0,
            'sell/max_hold_days' => 60.0,
            'sell/stale_action' => 'markdown',
            'sell/stale_markdown_percent' => 25.0,
        ];
    }
}
