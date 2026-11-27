<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\SellPlan;

use Magento\Framework\Data\OptionSourceInterface;

/** How far a part's price can be trusted. */
class Confidence implements OptionSourceInterface
{
    public const HIGH = 'high';
    public const MEDIUM = 'medium';
    public const LOW = 'low';
    public const NONE = 'none';

    private const RANK = [self::NONE => 0, self::LOW => 1, self::MEDIUM => 2, self::HIGH => 3];

    /** High from 10 or more sold comparables, medium from 5 to 9; your own estimates and thinner data are low. */
    public static function of(?float $price, string $source, int $comps): string
    {
        if ($price === null || $price <= 0) {
            return self::NONE;
        }
        if ($source !== 'comps') {
            return self::LOW;
        }
        return $comps >= 10 ? self::HIGH : ($comps >= 5 ? self::MEDIUM : self::LOW);
    }

    public static function atLeast(string $confidence, string $minimum): bool
    {
        return (self::RANK[$confidence] ?? 0) >= (self::RANK[$minimum] ?? 2);
    }

    public static function lowest(array $confidences): string
    {
        if ($confidences === []) {
            return self::NONE;
        }
        usort($confidences, static fn($a, $b) => (self::RANK[$a] ?? 0) <=> (self::RANK[$b] ?? 0));
        return $confidences[0];
    }

    public function toOptionArray(): array
    {
        return [
            ['value' => self::HIGH, 'label' => __('High: 10 or more sold comparables')],
            ['value' => self::MEDIUM, 'label' => __('Medium: 5 or more sold comparables')],
            ['value' => self::LOW, 'label' => __('Low: count my own estimates too')],
        ];
    }
}
