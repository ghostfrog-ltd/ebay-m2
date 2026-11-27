<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Test\Unit\Model\StockUnit;

use GhostFrog\EbayLedger\Model\StockUnit\Reference;
use PHPUnit\Framework\TestCase;

class ReferenceTest extends TestCase
{
    public function testReferencesArePaddedToSixDigits(): void
    {
        $this->assertSame('GF-000001', Reference::format('GF-', 1));
        $this->assertSame('GF-012345', Reference::format('GF-', 12345));
        $this->assertSame('GF-1234567', Reference::format('GF-', 1234567));
    }

    public function testSkus(): void
    {
        $this->assertSame('GF-000001', Reference::sku('GF-000001'));
        $this->assertSame('GF-000001-01', Reference::sku('GF-000001', 1));
        $this->assertSame('GF-000001-12', Reference::sku('GF-000001', 12));
    }

    public function testRejectsBadNumbers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Reference::format('GF-', 0);
    }
}
