<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Comps;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Which sold prices value a resale. "All sales" takes the median of every sale of the model and grade.
 * "Buy It Now" assumes you relist at a fixed price and takes the median of the cohort's Buy It Now sales,
 * which for Apple and Samsung close above auctions of the same model and grade and for consoles below.
 * It needs MIN_BIN_SALES of them; a thinner cohort falls back to all sales. The cohort must still meet
 * the source's sold-comp minimum across all sales either way.
 */
class ResaleBasis implements OptionSourceInterface
{
    public const SETTING = 'resale_basis';
    public const ALL = 'all';
    public const BIN = 'bin';
    public const MIN_BIN_SALES = 5;

    public function __construct(private readonly ?ScopeConfigInterface $config = null)
    {
    }

    public function toOptionArray(): array
    {
        return [
            ['value' => self::ALL, 'label' => __('All sales (auction and Buy It Now)')],
            ['value' => self::BIN, 'label' => __('Buy It Now sales, when there are at least %1', self::MIN_BIN_SALES)],
        ];
    }

    public function current(): string
    {
        $value = $this->config?->getValue(Pricing::CONFIG_PREFIX . self::SETTING);
        return $value === self::BIN ? self::BIN : self::ALL;
    }

    /**
     * @param array $comp Comps row with median_final_price, bin_median_final_price and bin_samples.
     * @return array{0: float, 1: string} Resale median in GBP and the basis it came from.
     */
    public static function median(array $comp, string $basis): array
    {
        $binMedian = (float)($comp['bin_median_final_price'] ?? 0);
        if ($basis === self::BIN && (int)($comp['bin_samples'] ?? 0) >= self::MIN_BIN_SALES && $binMedian > 0) {
            return [$binMedian, self::BIN];
        }
        return [(float)($comp['median_final_price'] ?? 0), self::ALL];
    }
}
