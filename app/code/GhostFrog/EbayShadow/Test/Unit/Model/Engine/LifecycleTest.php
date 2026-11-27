<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Test\Unit\Model\Engine;

use GhostFrog\EbayShadow\Model\Engine\Lifecycle;
use PHPUnit\Framework\TestCase;

final class LifecycleTest extends TestCase
{
    public function testPlanAddsEachStageInOrder(): void
    {
        $bought = strtotime('2026-11-01 12:00:00 UTC');
        $plan = Lifecycle::plan(SettingsFixture::defaults(), $bought, 5.5);
        $day = static fn(int $time): string => gmdate('Y-m-d H:i', $time);
        self::assertSame('2026-11-05 12:00', $day($plan['received']));
        self::assertSame('2026-11-07 12:00', $day($plan['listed']));
        self::assertSame('2026-11-13 00:00', $day($plan['sold']));
        self::assertSame('2026-11-16 00:00', $day($plan['delivered']));
        self::assertSame('2026-11-18 00:00', $day($plan['paid_out']));
        self::assertSame('2026-12-16 00:00', $day($plan['banked']));
    }

    public function testStageAtFollowsTheLatestPassedTime(): void
    {
        $times = ['received_at' => 100, 'listed_at' => 200, 'sold_at' => null, 'paid_out_at' => null, 'banked_at' => null];
        self::assertSame('inbound', Lifecycle::stageAt($times, 99));
        self::assertSame('received', Lifecycle::stageAt($times, 150));
        self::assertSame('listed', Lifecycle::stageAt($times, 10_000));
        $sold = ['sold_at' => 300, 'paid_out_at' => 400, 'banked_at' => 500] + $times;
        self::assertSame('outbound', Lifecycle::stageAt($sold, 350));
        self::assertSame('paid_out', Lifecycle::stageAt($sold, 450));
        self::assertSame('banked', Lifecycle::stageAt($sold, 500));
    }
}
