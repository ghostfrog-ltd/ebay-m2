<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Test\Unit\Model\Finance;

use GhostFrog\EbayLedger\Model\Finance\TransactionParser;
use PHPUnit\Framework\TestCase;

class TransactionParserTest extends TestCase
{
    private function transactions(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../../_files/finances_transactions.json'), true)['transactions'];
    }

    public function testSaleFeesArePerLineCostsAndZeroFeesAreSkipped(): void
    {
        $fees = TransactionParser::fees($this->transactions()[0]);
        $this->assertSame(['FINAL_VALUE_FEE', 'FINAL_VALUE_FEE_FIXED_PER_ORDER'], array_column($fees, 'fee_type'));
        $this->assertSame([12.29, 0.30], array_column($fees, 'amount'));
        $this->assertSame(['10055500001', '10055500001'], array_column($fees, 'ebay_line_item_id'));
        $this->assertSame('12-34567-00001', $fees[0]['ebay_order_id']);
        $this->assertSame('P-1', $fees[0]['payout_id']);
        $this->assertNotSame($fees[0]['fee_key'], $fees[1]['fee_key']);
    }

    public function testPostageLabelsAndAdFeesAreOrderCosts(): void
    {
        [$label] = TransactionParser::fees($this->transactions()[1]);
        $this->assertSame(['SHIPPING_LABEL', 3.35, '12-34567-00001'], [$label['fee_type'], $label['amount'], $label['ebay_order_id']]);
        [$ad] = TransactionParser::fees($this->transactions()[2]);
        $this->assertSame(['AD_FEE', 2.40], [$ad['fee_type'], $ad['amount']]);
    }

    public function testRefundsGiveFeesBack(): void
    {
        [$credit] = TransactionParser::fees($this->transactions()[3]);
        $this->assertSame(-0.51, $credit['amount']);
        $this->assertSame('REFUND', $credit['transaction_type']);
    }

    public function testFeeKeysAreStableSoImportsDoNotDuplicate(): void
    {
        $this->assertSame(TransactionParser::fees($this->transactions()[0]), TransactionParser::fees($this->transactions()[0]));
    }

    public function testSaleCreditRecordsThePayout(): void
    {
        $this->assertSame(['transaction_id' => 'T-SALE-1', 'ebay_order_id' => '12-34567-00001', 'payout_id' => 'P-1',
            'net_amount' => 106.91, 'booked_at' => '2026-12-08 19:13:00'], TransactionParser::saleCredit($this->transactions()[0]));
        $this->assertNull(TransactionParser::saleCredit($this->transactions()[1]));
    }
}
