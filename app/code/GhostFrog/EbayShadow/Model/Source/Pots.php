<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\Source;

use GhostFrog\EbayShadow\Model\Pots as PotStore;
use Magento\Framework\Data\OptionSourceInterface;

class Pots implements OptionSourceInterface
{
    public function __construct(private readonly PotStore $pots)
    {
    }

    public function toOptionArray(): array
    {
        return array_map(static fn(array $pot): array => ['value' => $pot['pot_id'], 'label' => $pot['name']],
            $this->pots->all());
    }
}
