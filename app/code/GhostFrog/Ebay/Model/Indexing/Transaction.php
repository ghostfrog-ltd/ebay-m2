<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Indexing;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Lock\LockManagerInterface;
use RuntimeException;
use Throwable;

final class Transaction
{
    private const LOCK = 'ghostfrog_ebay_comps';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LockManagerInterface $lock
    ) {
    }

    public function run(callable $write): void
    {
        if (!$this->lock->lock(self::LOCK, 0)) {
            throw new RuntimeException('GhostFrog eBay indexing is already running.');
        }
        try {
            $db = $this->resource->getConnection();
            $db->beginTransaction();
            try {
                $write();
                $db->commit();
            } catch (Throwable $exception) {
                $db->rollBack();
                throw $exception;
            }
        } finally {
            $this->lock->unlock(self::LOCK);
        }
    }
}
