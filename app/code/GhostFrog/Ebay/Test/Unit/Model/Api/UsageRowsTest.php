<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Test\Unit\Model\Api;

use GhostFrog\Ebay\Model\Api\Usage;
use GhostFrog\Ebay\Model\Api\UsageRows;
use PHPUnit\Framework\TestCase;

final class UsageRowsTest extends TestCase
{
    public function testEachApiBecomesOneRowWithTodaysUsage(): void
    {
        $rows = UsageRows::fromSummary(['date' => '2026-11-25', 'buckets' => [
            Usage::BROWSE => $this->state([
                'used' => 120,
                'limit' => 5000,
                'remaining' => 4000,
                'reported_remaining' => 4100,
                'checked_at' => 1795593600,
                'reset_at' => 1795651200,
                'services' => [Usage::CATEGORY_SEARCH => 100, Usage::ITEM_REFRESH => 20],
            ]),
            Usage::ANALYTICS => $this->state([
                'used' => 2,
                'pause_reason' => 'analytics_retry',
                'retry_at' => 1795593900,
                'services' => [Usage::RATE_LIMITS => 2],
            ]),
        ]]);

        self::assertCount(2, $rows);
        self::assertSame('Browse API', $rows[0]['api_name']);
        self::assertSame(120, $rows[0]['calls_today']);
        self::assertSame(5000, $rows[0]['daily_limit']);
        self::assertSame(4000, $rows[0]['remaining']);
        self::assertSame(20.0, $rows[0]['used_percent']);
        self::assertSame('Available', $rows[0]['status']);
        self::assertSame('2026-11-25 08:00 UTC', $rows[0]['checked_at']);
        self::assertSame('Category search: 100, Item price refresh: 20', $rows[0]['services']);

        self::assertNull($rows[1]['daily_limit']);
        self::assertNull($rows[1]['remaining']);
        self::assertNull($rows[1]['used_percent']);
        self::assertSame('Paused: waiting to re-check quota', $rows[1]['status']);
        self::assertSame('2026-11-25 08:05 UTC', $rows[1]['retry_at']);
    }

    private function state(array $values): array
    {
        return $values + [
            'used' => 0, 'limit' => null, 'remaining' => null, 'reported_remaining' => null,
            'checked_at' => null, 'reset_at' => null, 'retry_at' => 0, 'pause_reason' => null, 'services' => [],
        ];
    }
}
