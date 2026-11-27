<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Test\Unit\Model\SellPlan;

use GhostFrog\EbayLedger\Model\SellPlan\Calculator;
use PHPUnit\Framework\TestCase;

class CalculatorTest extends TestCase
{
    private static function input(array $overrides = []): array
    {
        $part = static fn(string $name, ?float $price, string $source, int $comps = 0, string $group = '', string $band = 'small')
            => compact('name', 'price', 'source', 'comps', 'group') + ['size_band' => $band, 'days' => $comps ? 90 / $comps : null];
        return $overrides + [
            'unit_cost' => 32.97,
            'fee_percent' => 10.0,
            'per_order_fee' => 0.30,
            'packaging' => 1.00,
            'effort_cost' => 2.00,
            'postage' => ['small' => 3.00, 'medium' => 4.00, 'large' => 7.00],
            'min_confidence' => 'medium',
            'whole' => ['price' => 100.0, 'comps' => 20, 'source' => 'comps', 'days' => 4.5, 'size_band' => 'large'],
            'parts' => [
                $part('Console', 80.0, 'comps', 13, 'Console and dock', 'medium'),
                $part('Dock', 20.0, 'estimate', 0, 'Console and dock', 'medium'),
                $part('Joy-Con pair', 30.0, 'estimate'),
            ],
            'missing' => [],
        ];
    }

    public function testWholeSaleProfitMaths(): void
    {
        $whole = Calculator::plan(self::input())['options']['whole'];
        // 100 - (10.00 + 0.30) fees - 7.00 postage - 1.00 packaging = 81.70 net; less 32.97 cost.
        $this->assertSame(10.30, $whole['lines'][0]['fees']);
        $this->assertSame(81.70, $whole['lines'][0]['net']);
        $this->assertSame(48.73, $whole['certain_profit']);
        $this->assertSame(48.73, $whole['expected_profit']);
        $this->assertSame(1, $whole['sales']);
    }

    public function testPartOutCountsEffortAndLeavesEstimatesOutOfCertainProfit(): void
    {
        $partOut = Calculator::plan(self::input())['options']['part_out'];
        // Console 80 - 8.30 - 4 - 1 = 66.70; Dock 20 - 2.30 - 4 - 1 = 12.70; Joy-Con 30 - 3.30 - 3 - 1 = 22.70.
        $this->assertSame(4.00, $partOut['effort']);
        $this->assertSame(round(66.70 + 12.70 + 22.70 - 4.00 - 32.97, 2), $partOut['expected_profit']);
        $this->assertSame(round(66.70 - 4.00 - 32.97, 2), $partOut['certain_profit']);
        $this->assertSame(['Dock', 'Joy-Con pair'], $partOut['flagged']);
    }

    public function testMixedGroupsSellTogether(): void
    {
        $mixed = Calculator::plan(self::input())['options']['mixed'];
        $this->assertSame(['Console + Dock', 'Joy-Con pair'], array_column($mixed['lines'], 'name'));
        $this->assertSame(100.0, $mixed['lines'][0]['price']);
        $this->assertSame('low', $mixed['lines'][0]['confidence']);
    }

    public function testRecommendsTheBestCertainProfitAndExplains(): void
    {
        $plan = Calculator::plan(self::input());
        $this->assertSame('whole', $plan['recommended']);
        $this->assertStringContainsString('Sell whole has the highest certain profit: £48.73', $plan['reasons'][0]);

        // Counting your own estimates, console and dock together plus Joy-Cons alone makes the most: £72.43.
        $plan = Calculator::plan(self::input(['min_confidence' => 'low']));
        $this->assertSame('mixed', $plan['recommended']);
        $this->assertSame(72.43, $plan['options']['mixed']['certain_profit']);
    }

    public function testMissingPartsComeOffTheWholePrice(): void
    {
        $input = self::input();
        $input['missing'] = [array_pop($input['parts'])];
        $whole = Calculator::plan($input)['options']['whole'];
        $this->assertSame(70.0, $whole['lines'][0]['price']);
        $this->assertSame('low', $whole['lines'][0]['confidence']);
        $this->assertStringContainsString('Missing Joy-Con pair', $whole['lines'][0]['notes'][0]);
    }

    public function testUnpricedPartsAreFlagged(): void
    {
        $input = self::input();
        $input['parts'][2]['price'] = null;
        $input['parts'][2]['source'] = 'none';
        $partOut = Calculator::plan($input)['options']['part_out'];
        $this->assertFalse($partOut['complete']);
        $this->assertContains('Joy-Con pair', $partOut['flagged']);
    }
}
