<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Test\Unit\Model\PriceRefresh;

use GhostFrog\Ebay\Model\PriceRefresh\Pause;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Cache\FrontendInterface;
use PHPUnit\Framework\TestCase;

final class PauseTest extends TestCase
{
    private const KEY = 'ghostfrog_ebay_price_refresh_pause';

    public function testOldQuotaMarkerStopsBlockingAfterTheAuthenticationWindow(): void
    {
        self::assertNull($this->legacyPause(time() - 3601)->state());
    }

    public function testRecentOrUndatedLegacyMarkersCanStillBeAuthenticationPauses(): void
    {
        $recent = time() - 1800;
        self::assertSame(['reason' => 'legacy_pause', 'retry_at' => $recent + 3600], $this->legacyPause($recent)->state());
        self::assertSame(['reason' => 'legacy_pause', 'retry_at' => null], $this->legacyPause(false)->state());
    }

    private function legacyPause(int|false $modifiedAt): Pause
    {
        $frontend = $this->createMock(FrontendInterface::class);
        $frontend->expects(self::once())->method('test')->with(self::KEY)->willReturn($modifiedAt);
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())->method('load')->with(self::KEY)->willReturn('1');
        $cache->method('getFrontend')->willReturn($frontend);
        $cache->expects(self::never())->method('save');
        $cache->expects(self::never())->method('remove');
        return new Pause($cache);
    }
}
