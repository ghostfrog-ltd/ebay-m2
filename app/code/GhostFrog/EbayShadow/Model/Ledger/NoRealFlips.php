<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\Ledger;

use GhostFrog\EbayShadow\Api\RealFlipReaderInterface;

/** Used until GhostFrog_EbayLedger exists. */
final class NoRealFlips implements RealFlipReaderInterface
{
    public function available(): bool
    {
        return false;
    }

    public function flips(string $fromUtc, string $untilUtc): array
    {
        return [];
    }
}
