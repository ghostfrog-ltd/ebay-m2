<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Carrier;

/** The postage eBay charged the buyer, held while Ledger builds the order for that sale. */
class Postage
{
    private ?float $amount = null;

    public function set(?float $amount): void
    {
        $this->amount = $amount;
    }

    public function get(): ?float
    {
        return $this->amount;
    }
}
