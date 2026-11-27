<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Test\Unit\Model;

use GhostFrog\EbayLedger\Model\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function testPartsAddUpToThePenny(): void
    {
        $parts = Money::split(100.00, [1, 1, 1]);
        $this->assertSame([0 => 33.34, 1 => 33.33, 2 => 33.33], $parts);
        $this->assertSame(10000, (int)round(array_sum($parts) * 100));
    }

    public function testSplitsInProportionToWeights(): void
    {
        $this->assertSame(['console' => 150.0, 'dock' => 30.0, 'box' => 20.0],
            Money::split(200.00, ['console' => 150, 'dock' => 30, 'box' => 20]));
    }

    public function testAwkwardAmountsStillAddUp(): void
    {
        foreach ([[187.49, [120, 35, 22.5, 9.99, 4]], [0.05, [1, 1, 1, 1, 1, 1, 1]], [-1.01, [2, 1]]] as [$amount, $weights]) {
            $parts = Money::split($amount, $weights);
            $this->assertSame((int)round($amount * 100), (int)round(array_sum($parts) * 100));
            foreach ($parts as $part) {
                $this->assertSame(round($part, 2), $part);
            }
        }
    }

    public function testNoWeightsSplitsEvenly(): void
    {
        $this->assertSame([0 => 0.5, 1 => 0.5], Money::split(1.00, [0, 0]));
    }
}
