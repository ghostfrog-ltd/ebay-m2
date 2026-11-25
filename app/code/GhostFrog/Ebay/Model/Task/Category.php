<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Task;

use Magento\Framework\Data\OptionSourceInterface;

final class Category implements OptionSourceInterface
{
    public const GOAL = 'goal';
    public const FEATURE = 'feature';
    public const IMPROVEMENT = 'improvement';
    public const BUG = 'bug';
    public const RESEARCH = 'research';
    public const TASK = 'task';

    public const LABELS = [
        self::GOAL => 'Goal',
        self::FEATURE => 'Feature',
        self::IMPROVEMENT => 'Improvement',
        self::BUG => 'Bug',
        self::RESEARCH => 'Research',
        self::TASK => 'Task',
    ];

    public function toOptionArray(): array
    {
        $options = [];
        foreach (self::LABELS as $value => $label) {
            $options[] = ['value' => $value, 'label' => __($label)];
        }
        return $options;
    }
}
