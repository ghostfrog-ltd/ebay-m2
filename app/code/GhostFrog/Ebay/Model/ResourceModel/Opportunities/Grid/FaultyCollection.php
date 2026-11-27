<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ResourceModel\Opportunities\Grid;

/** Faulty opportunities: listings valued only against faulty sales of the same model. */
class FaultyCollection extends Collection
{
    protected const FAULTY = true;
}
