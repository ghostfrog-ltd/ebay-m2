<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Ui\Component\Listing\Column;

use GhostFrog\Ebay\Model\Condition\Assessment;
use Magento\Framework\Data\OptionSourceInterface;

class ConditionGrades implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return array_map(static fn (string $grade): array => ['value' => $grade, 'label' => $grade], Assessment::GRADES);
    }
}
