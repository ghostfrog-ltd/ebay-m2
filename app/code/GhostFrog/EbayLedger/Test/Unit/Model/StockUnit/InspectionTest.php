<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Test\Unit\Model\StockUnit;

use GhostFrog\EbayLedger\Model\StockUnit\Inspection;
use GhostFrog\EbayLedger\Model\StockUnit\Status;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class InspectionTest extends TestCase
{
    private const UNIT = [
        'status' => Status::IN_TRANSIT, 'total_cost' => '32.97', 'purchase_method' => 'auction',
        'shipped_at' => '2026-11-27 11:30:34',
    ];

    public function testReceivingRecordsGradeAndContents(): void
    {
        $changes = Inspection::apply(self::UNIT, [
            'received_at' => '2026-11-26', 'actual_grade' => 'c', 'contents' => "Console\n Dock \n\nJoy-Con pair",
            'faults' => 'Left Joy-Con drifts', 'outcome' => 'keep', 'purchase_method' => 'auction',
        ]);
        $this->assertSame(Status::RECEIVED, $changes['status']);
        $this->assertSame('2026-11-26', $changes['received_at']);
        $this->assertSame('C', $changes['actual_grade']);
        $this->assertSame('["Console","Dock","Joy-Con pair"]', $changes['contents']);
        $this->assertSame('Left Joy-Con drifts', $changes['faults']);
        $this->assertArrayNotHasKey('purchase_method', $changes);
    }

    public function testNotYetArrivedStaysInTransit(): void
    {
        $this->assertSame(Status::IN_TRANSIT, Inspection::apply(self::UNIT, ['outcome' => 'keep'])['status']);
    }

    public function testReturnedWithFullRefund(): void
    {
        $changes = Inspection::apply(self::UNIT, [
            'received_at' => '2026-11-26', 'outcome' => Status::RETURNED_TO_SELLER, 'refund_amount' => '£32.97',
            'refunded_at' => '2026-11-27',
        ]);
        $this->assertSame(Status::RETURNED_TO_SELLER, $changes['status']);
        $this->assertSame(32.97, $changes['refund_amount']);
        $this->assertSame('2026-11-27', $changes['refunded_at']);
    }

    public function testRefundCannotExceedWhatWasPaid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Inspection::apply(self::UNIT, ['refund_amount' => '40']);
    }

    public function testChangingTheMethodMarksItManual(): void
    {
        $changes = Inspection::apply(self::UNIT, ['purchase_method' => 'best_offer']);
        $this->assertSame(['best_offer', 'manual'], [$changes['purchase_method'], $changes['purchase_method_source']]);
    }

    public function testListedUnitsKeepTheirStatus(): void
    {
        $unit = ['status' => Status::LISTED] + self::UNIT;
        $this->assertArrayNotHasKey('status', Inspection::apply($unit, ['received_at' => '2026-11-26', 'actual_grade' => 'B']));
        $this->expectException(InvalidArgumentException::class);
        Inspection::apply($unit, ['outcome' => Status::WRITTEN_OFF]);
    }

    public function testUkDatesAreAccepted(): void
    {
        $this->assertSame('2026-11-03', Inspection::apply(self::UNIT, ['received_at' => '03/11/2026'])['received_at']);
    }
}
