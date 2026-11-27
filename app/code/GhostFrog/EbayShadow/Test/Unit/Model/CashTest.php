<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Test\Unit\Model;

use GhostFrog\EbayShadow\Model\Cash;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

/**
 * Cash movements are idempotent by key: the table has a unique idempotency_key, and record() writes with
 * INSERT ... ON DUPLICATE KEY UPDATE on that key alone, so a repeat leaves the row as it was.
 */
final class CashTest extends TestCase
{
    public function testRepeatedMovementIsRecordedOnce(): void
    {
        $rows = [];
        $db = $this->createMock(AdapterInterface::class);
        $db->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $row, array $update) use (&$rows): int {
                self::assertSame(['idempotency_key'], $update);
                if (isset($rows[$row['idempotency_key']])) {
                    return 0;
                }
                $rows[$row['idempotency_key']] = $row;
                return 1;
            }
        );
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);
        $cash = new Cash($resource);

        self::assertTrue($cash->record(1, Cash::BUY_DEBIT, -95.123, 'buy:7', '2026-11-01 00:00:00', 7));
        self::assertFalse($cash->record(1, Cash::BUY_DEBIT, -95.123, 'buy:7', '2026-11-01 00:00:00', 7));
        self::assertTrue($cash->record(1, Cash::PAYOUT_CREDIT, 140.0, 'payout:7', '2026-11-20 00:00:00', 7));
        self::assertCount(2, $rows);
        self::assertSame(-95.12, $rows['buy:7']['amount']);
    }
}
