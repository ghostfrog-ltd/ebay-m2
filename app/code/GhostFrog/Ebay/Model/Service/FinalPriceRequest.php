<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Service;

use GhostFrog\Ebay\Api\FinalPriceRequestInterface;
use GhostFrog\Ebay\Model\FinalPrice\Queue;

final class FinalPriceRequest implements FinalPriceRequestInterface
{
    public function __construct(private readonly Queue $queue)
    {
    }

    public function request(array $listingIds): int
    {
        return $this->queue->prioritise($listingIds);
    }
}
