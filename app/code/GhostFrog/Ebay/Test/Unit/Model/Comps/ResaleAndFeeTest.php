<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Test\Unit\Model\Comps;

use GhostFrog\Ebay\Model\Comps\Index;
use GhostFrog\Ebay\Model\Comps\Pricing;
use GhostFrog\Ebay\Model\Comps\ResaleBasis;
use GhostFrog\Ebay\Model\ResourceModel\Source;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Per-source selling fees and valuing resales from Buy It Now sales. */
final class ResaleAndFeeTest extends TestCase
{
    public function testSourceFeeOverridesTheGlobalFee(): void
    {
        $pricing = new Pricing($this->config(['selling_fee_percent' => '13', 'shipping' => '12']));

        self::assertSame(13.0, $pricing->sellingFee());
        self::assertSame(13.0, $pricing->sellingFee(['source_selling_fee_percent' => null]));
        self::assertSame(13.0, $pricing->sellingFee(['source_selling_fee_percent' => '']));
        self::assertSame(6.9, $pricing->sellingFee(['source_selling_fee_percent' => '6.90']));
        self::assertSame(0.0, $pricing->sellingFee(['source_selling_fee_percent' => '0.00']));

        // A £420 median nets 420 * 0.87 - 12 - 5 = 348.40 at 13%, and 420 * 0.931 - 17 = 374.02 at 6.9%.
        // A £234 buy pays £0.10 + £1.40 + £8.56 = £10.06 Buyer Protection either way.
        self::assertSame(104.34, $pricing->calculate(420, 234)['expected_profit']);
        self::assertSame(129.96, $pricing->calculate(420, 234, ['source_selling_fee_percent' => '6.9'])['expected_profit']);
    }

    public function testSourceFeeIsValidatedAsAPercentage(): void
    {
        self::assertSame('6.90', Source::minimum(' 6.9 ', 'selling_fee_percent'));
        self::assertSame('0.00', Source::minimum('0', 'selling_fee_percent'));
        self::assertNull(Source::minimum('', 'selling_fee_percent'));

        $this->expectException(LocalizedException::class);
        Source::minimum('100.01', 'selling_fee_percent');
    }

    public function testResaleBasisSettingInvalidatesOpportunities(): void
    {
        self::assertTrue(Pricing::isSettingPath('ghostfrog_ebay/comps/resale_basis'));
        self::assertFalse(Pricing::isSettingPath('ghostfrog_ebay/comps/resale_basis_other'));
    }

    public function testResaleMedianUsesBuyItNowSalesOnlyWhenChosenAndThickEnough(): void
    {
        $comp = ['median_final_price' => '400', 'bin_median_final_price' => '440', 'bin_samples' => '5'];

        self::assertSame([400.0, ResaleBasis::ALL], ResaleBasis::median($comp, ResaleBasis::ALL));
        self::assertSame([440.0, ResaleBasis::BIN], ResaleBasis::median($comp, ResaleBasis::BIN));
        // Fewer than five Buy It Now sales falls back to all sales.
        self::assertSame([400.0, ResaleBasis::ALL], ResaleBasis::median(['bin_samples' => '4'] + $comp, ResaleBasis::BIN));
        self::assertSame([400.0, ResaleBasis::ALL], ResaleBasis::median(
            ['bin_median_final_price' => null, 'bin_samples' => '0'] + $comp,
            ResaleBasis::BIN
        ));
        // A lower Buy It Now median is used too: that is what fixed-price resale fetches.
        self::assertSame([380.0, ResaleBasis::BIN], ResaleBasis::median(
            ['bin_median_final_price' => '380'] + $comp,
            ResaleBasis::BIN
        ));
    }

    public function testResaleBasisReadsTheSettingAndDefaultsToAllSales(): void
    {
        self::assertSame(ResaleBasis::ALL, (new ResaleBasis())->current());
        self::assertSame(ResaleBasis::ALL, (new ResaleBasis($this->config([])))->current());
        self::assertSame(ResaleBasis::ALL, (new ResaleBasis($this->config(['resale_basis' => 'nonsense'])))->current());
        self::assertSame(ResaleBasis::BIN, (new ResaleBasis($this->config(['resale_basis' => 'bin'])))->current());
    }

    public function testCompStatisticsKeepABuyItNowMedianPerCohort(): void
    {
        $sale = static fn(string $grade, string $price, ?string $type): array => [
            'model_key' => 'apple-iphone-15-pro-max-256gb', 'condition_grade' => $grade,
            'final_price' => $price, 'sale_type' => $type,
        ];
        $rows = $this->statistics([
            $sale('B', '400', 'auction'),
            $sale('B', '410', 'both'),
            $sale('B', '430', 'bin'),
            $sale('B', '450', 'bin'),
            $sale('B', '390', null),
            $sale('C', '300', 'auction'),
        ]);
        $byGrade = array_column($rows, null, 'condition_grade');

        self::assertSame(410.0, $byGrade['B']['median_final_price']);
        self::assertSame(5, $byGrade['B']['samples']);
        // Only fixed-price sales count; a combined listing's sale could have closed either way.
        self::assertSame(440.0, $byGrade['B']['bin_median_final_price']);
        self::assertSame(2, $byGrade['B']['bin_samples']);
        self::assertNull($byGrade['C']['bin_median_final_price']);
        self::assertSame(0, $byGrade['C']['bin_samples']);
    }

    private function statistics(array $sales): array
    {
        $index = (new ReflectionClass(Index::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Index::class, 'statistics');
        return $method->invoke($index, $sales, '2026-11-27 09:00:00');
    }

    private function config(array $values): ScopeConfigInterface
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(
            static fn(string $path): ?string => $values[substr($path, strlen(Pricing::CONFIG_PREFIX))] ?? null
        );
        return $config;
    }
}
