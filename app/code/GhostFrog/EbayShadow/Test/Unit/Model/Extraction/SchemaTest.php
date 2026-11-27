<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Test\Unit\Model\Extraction;

use GhostFrog\EbayShadow\Model\Extraction\Schema;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SchemaTest extends TestCase
{
    private static function valid(array $override = []): array
    {
        return $override + [
            'included_items' => ['Console', 'Charger'], 'bundle_contents' => [], 'faults' => [],
            'untested' => false, 'spares_or_repairs' => false, 'locked_or_blacklisted' => false,
            'battery_health_percent' => null, 'confidence' => 0.9,
        ];
    }

    public function testValidOutputPassesAndNullBatteryIsKept(): void
    {
        $fields = Schema::validate(self::valid());
        self::assertNull($fields['battery_health_percent']);
        self::assertNull(Schema::risk($fields));
        self::assertSame(88.0, Schema::validate(self::valid(['battery_health_percent' => '88%']))['battery_health_percent']);
    }

    public function testRiskFlagsRuleAListingOut(): void
    {
        self::assertSame('locked', Schema::risk(Schema::validate(self::valid(['locked_or_blacklisted' => true]))));
        self::assertSame('faults', Schema::risk(Schema::validate(self::valid(['faults' => ['No power']]))));
    }

    /** @dataProvider invalid */
    public function testInvalidOutputIsRejected(array $raw): void
    {
        $this->expectException(RuntimeException::class);
        Schema::validate($raw);
    }

    public static function invalid(): array
    {
        return [
            'missing field' => [array_diff_key(self::valid(), ['faults' => 1])],
            'string flag' => [self::valid(['untested' => 'no'])],
            'confidence above 1' => [self::valid(['confidence' => 3])],
            'battery above 100' => [self::valid(['battery_health_percent' => 140])],
            'extra field' => [self::valid(['verdict' => 'buy'])],
        ];
    }
}
