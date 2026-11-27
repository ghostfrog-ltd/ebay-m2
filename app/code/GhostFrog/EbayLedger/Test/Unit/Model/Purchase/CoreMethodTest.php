<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Test\Unit\Model\Purchase;

use GhostFrog\EbayLedger\Model\Purchase\Importer;
use PHPUnit\Framework\TestCase;

class CoreMethodTest extends TestCase
{
    private const UNKNOWN = ['purchase_method' => 'unknown', 'purchase_method_source' => null];

    public function testCoreAuctionFillsAnUnknownMethod(): void
    {
        $this->assertSame(['purchase_method' => 'auction', 'purchase_method_source' => 'core'],
            Importer::withCoreMethod(self::UNKNOWN, ['sale_type' => 'auction']));
    }

    public function testBuyItNowListingsStayUnknown(): void
    {
        $this->assertSame(self::UNKNOWN, Importer::withCoreMethod(self::UNKNOWN, ['sale_type' => 'bin']));
        $this->assertSame(self::UNKNOWN, Importer::withCoreMethod(self::UNKNOWN, null));
    }

    public function testEbaysOwnAnswerWins(): void
    {
        $unit = ['purchase_method' => 'best_offer', 'purchase_method_source' => 'ebay'];
        $this->assertSame($unit, Importer::withCoreMethod($unit, ['sale_type' => 'auction']));
    }
}
