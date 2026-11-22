<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Api;

use RuntimeException;

final class QuotaUnavailable extends RuntimeException
{
    public function __construct(public readonly string $bucket, public readonly int $retryAt)
    {
        parent::__construct(sprintf('eBay %s quota unavailable until %s.', $bucket, gmdate(DATE_ATOM, $retryAt)));
    }
}
