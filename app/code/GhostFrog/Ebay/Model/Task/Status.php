<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Task;

use Magento\Framework\Data\OptionSourceInterface;

final class Status implements OptionSourceInterface
{
    public const OPEN = 'open';
    public const IN_PROGRESS = 'in_progress';
    public const BLOCKED = 'blocked';
    public const COMPLETE = 'complete';
    public const WONT_DO = 'wont_do';

    /** Statuses that close a task and record when it was closed. */
    public const CLOSED = [self::COMPLETE, self::WONT_DO];

    public const LABELS = [
        self::OPEN => 'Open',
        self::IN_PROGRESS => 'In Progress',
        self::BLOCKED => 'Blocked',
        self::COMPLETE => 'Complete',
        self::WONT_DO => "Won't Do",
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
