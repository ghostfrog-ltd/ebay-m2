<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Test\Unit\Model\Engine;

use GhostFrog\EbayShadow\Model\Engine\Selector;
use PHPUnit\Framework\TestCase;

final class SelectorTest extends TestCase
{
    private static function candidate(int $key, float $score, float $reserve, string $category = 'ebay-apple'): array
    {
        return ['key' => $key, 'category' => $category, 'score' => $score, 'reserve' => $reserve];
    }

    public function testBuysBestScoreFirstUntilCashRunsOut(): void
    {
        $result = Selector::select([
            self::candidate(1, 0.01, 60),
            self::candidate(2, 0.05, 80),
            self::candidate(3, 0.03, 60),
        ], 150, 150, 60, 100);
        self::assertSame('bought', $result[2]['outcome']);
        self::assertSame('bought', $result[3]['outcome']);
        self::assertSame('no_cash', $result[1]['reason']);
    }

    public function testItemCapSkipsAnItemLargerThanItsShareOfThePot(): void
    {
        $result = Selector::select([self::candidate(1, 0.1, 100)], 150, 150, 60, 100);
        self::assertSame(['outcome' => 'skipped', 'reason' => 'item_cap'], $result[1]);
    }

    public function testCategoryCapCountsCashAlreadyCommitted(): void
    {
        $result = Selector::select([
            self::candidate(1, 0.1, 40),
            self::candidate(2, 0.09, 40, 'ebay-consoles'),
        ], 1000, 1000, 60, 10, ['ebay-apple' => 70]);
        self::assertSame('category_cap', $result[1]['reason']);
        self::assertSame('bought', $result[2]['reason']);
    }

    public function testNoCashIsReportedBeforeTheCategoryCap(): void
    {
        $result = Selector::select([self::candidate(1, 0.1, 50)], 20, 150, 60, 30, ['ebay-apple' => 40]);
        self::assertSame('no_cash', $result[1]['reason']);
    }

    public function testCheaperLowerScoringItemStillFitsAfterAnUnaffordableOne(): void
    {
        $result = Selector::select([self::candidate(1, 0.2, 90), self::candidate(2, 0.1, 30)], 50, 150, 60, 100);
        self::assertSame('no_cash', $result[1]['reason']);
        self::assertSame('bought', $result[2]['reason']);
    }

    public function testEqualScoresAreTakenInKeyOrder(): void
    {
        $result = Selector::select([self::candidate(9, 0.1, 50), self::candidate(4, 0.1, 50)], 60, 150, 60, 100);
        self::assertSame('bought', $result[4]['reason']);
        self::assertSame('no_cash', $result[9]['reason']);
    }
}
