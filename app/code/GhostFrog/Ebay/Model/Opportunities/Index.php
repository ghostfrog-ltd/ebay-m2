<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Opportunities;

use GhostFrog\Ebay\Model\Indexing\Transaction;
use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;

class Index implements ActionInterface, MviewActionInterface
{
    public const ID = 'ghostfrog_ebay_opportunities';

    public function __construct(private readonly Transaction $transaction, private readonly Rows $rows)
    {
    }

    public function executeFull(): void
    {
        $this->transaction->run($this->rows->rebuild(...));
    }

    public function executeList(array $ids): void
    {
        $ids = array_unique(array_filter(array_map('intval', $ids), static fn (int $id) => $id > 0));
        if ($ids !== []) {
            $this->transaction->run(function () use ($ids): void {
                foreach (array_chunk($ids, 500) as $batch) {
                    $this->rows->refreshListings($batch);
                }
            });
        }
    }

    public function executeRow($id): void
    {
        $this->executeList([$id]);
    }

    public function execute($ids): void
    {
        $this->executeList($ids);
    }

    public function expire(): void
    {
        $this->transaction->run($this->rows->expire(...));
    }
}
