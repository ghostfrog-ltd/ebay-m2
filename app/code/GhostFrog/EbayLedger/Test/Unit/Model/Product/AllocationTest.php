<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Test\Unit\Model\Product;

use GhostFrog\EbayLedger\Model\Product\Allocation;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AllocationTest extends TestCase
{
    private const UNIT = [
        'reference' => 'GF-000001', 'title' => 'Nintendo Switch HAC-001', 'total_cost' => '32.97',
        'refund_amount' => '0.00', 'actual_grade' => 'B', 'predicted_grade' => 'F',
    ];

    private static function option(string $code, array $prices): array
    {
        $lines = [];
        foreach ($prices as $name => $price) {
            $lines[] = ['name' => $name, 'parts' => [$name], 'price' => $price];
        }
        return ['code' => $code, 'lines' => $lines];
    }

    public function testWholeUsesTheReferenceAsSkuAndTakesAllTheCost(): void
    {
        [$product] = Allocation::products(self::UNIT, self::option('whole', ['Whole item' => 109.99]));
        $this->assertSame('GF-000001', $product['sku']);
        $this->assertSame(0, $product['line_number']);
        $this->assertSame(32.97, $product['allocated_cost']);
        $this->assertSame('B', $product['grade']);
    }

    public function testPartsShareTheCostByPlannedPriceAndAddUpExactly(): void
    {
        $products = Allocation::products(self::UNIT,
            self::option('part_out', ['Console' => 83.93, 'Dock' => 20.0, 'Joy-Con pair' => 30.0]));
        $this->assertSame(['GF-000001-01', 'GF-000001-02', 'GF-000001-03'], array_column($products, 'sku'));
        $this->assertSame(3297, (int)round(array_sum(array_column($products, 'allocated_cost')) * 100));
        // 32.97 x 83.93 / 133.93 = 20.66; x 20 / 133.93 = 4.92; x 30 / 133.93 = 7.39 (rounding penny to the largest remainder).
        $this->assertSame([20.66, 4.92, 7.39], array_column($products, 'allocated_cost'));
    }

    public function testRefundsReduceTheCostShared(): void
    {
        $unit = ['refund_amount' => '10.00'] + self::UNIT;
        [$product] = Allocation::products($unit, self::option('whole', ['Whole item' => 50.0]));
        $this->assertSame(22.97, $product['allocated_cost']);
    }

    public function testEverySaleNeedsAPrice(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Box');
        Allocation::products(self::UNIT, self::option('part_out', ['Console' => 80.0, 'Box' => null]));
    }
}
