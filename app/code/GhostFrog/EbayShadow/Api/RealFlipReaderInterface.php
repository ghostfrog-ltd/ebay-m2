<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Api;

/**
 * Read-only seam to real purchases and sales, for calibrating shadow results.
 *
 * A future GhostFrog_EbayLedger module will provide this through a DI preference. Shadow only ever reads real
 * flips through it: it never writes to Ledger, and no simulated data goes into Ledger's tables. Without Ledger
 * the default implementation returns nothing and shadow works as before.
 */
interface RealFlipReaderInterface
{
    /** Whether a real-trade source is installed. */
    public function available(): bool;

    /**
     * Real completed flips that ended inside the window.
     *
     * Each row: external_id, model_key, condition_grade, bought_at, buy_cost, sold_at, sell_price, fees,
     * postage, net_proceeds, realised_profit.
     *
     * @return list<array<string, mixed>>
     */
    public function flips(string $fromUtc, string $untilUtc): array;
}
