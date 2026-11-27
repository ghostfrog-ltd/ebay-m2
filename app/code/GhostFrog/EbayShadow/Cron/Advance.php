<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Cron;

use GhostFrog\EbayShadow\Model\Advance as AdvanceJob;
use Magento\Framework\Lock\LockManagerInterface;

/** One run at a time; a run that finds the lock taken does nothing. */
class Advance
{
    public function __construct(private readonly AdvanceJob $job, private readonly LockManagerInterface $lock)
    {
    }

    public function execute(): void
    {
        if (!$this->lock->lock('ghostfrog_ebayshadow_advance', 0)) {
            return;
        }
        try {
            $this->job->run();
        } finally {
            $this->lock->unlock('ghostfrog_ebayshadow_advance');
        }
    }
}
