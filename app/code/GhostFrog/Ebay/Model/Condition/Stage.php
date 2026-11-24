<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Condition;

enum Stage: string
{
    case Text = 'text';
    case Visual = 'visual';

    public function queue(): string
    {
        return 'ghostfrog.ebay.condition_' . $this->value;
    }

    public function backfillQueue(): string
    {
        return $this->queue() . '_backfill';
    }

    public function assessmentTable(): string
    {
        return 'ghostfrog_ebay_listing_' . $this->value . '_conditions';
    }

    public function gradeField(): string
    {
        return $this->value . '_grade';
    }

    public function flagsField(): string
    {
        return match ($this) {
            self::Text => 'risk_flags',
            self::Visual => 'damage_flags',
        };
    }
}
