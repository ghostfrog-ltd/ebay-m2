<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Comps;

use Magento\Framework\App\Config\ScopeConfigInterface;

final class Pricing
{
    public const CONFIG_PREFIX = 'ghostfrog_ebay/comps/';
    public const SELLING_FEE = 'selling_fee_percent';

    private const DEFAULTS = ['target_profit' => 50.0, self::SELLING_FEE => 13.0, 'shipping' => 12.0];
    private const PACKAGING_AND_RESERVE = 5.0;

    public function __construct(private readonly ScopeConfigInterface $config)
    {
    }

    public function calculate(float $median, float $price): array
    {
        $target = $this->setting('target_profit');
        $net = $median * (1 - $this->setting(self::SELLING_FEE) / 100)
            - $this->setting('shipping') - self::PACKAGING_AND_RESERVE;
        $profit = round($net - $price, 2);
        $ceiling = max(0.0, round($net - $target, 2));

        return [
            'max_safe_buy' => $ceiling,
            'expected_profit' => $profit,
            'expected_roi_percent' => round(100 * $profit / $price, 2),
            'meets_target' => $price <= $ceiling && $profit >= $target,
        ];
    }

    public static function validSetting(mixed $value, string $field): bool
    {
        return is_scalar($value) && preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/', trim((string)$value))
            && (float)$value <= ($field === self::SELLING_FEE ? 100 : 999999999.99);
    }

    private function setting(string $field): float
    {
        $value = $this->config->getValue(self::CONFIG_PREFIX . $field);
        return self::validSetting($value, $field) ? (float)$value : self::DEFAULTS[$field];
    }
}
