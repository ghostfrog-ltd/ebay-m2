<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Cron;

use GhostFrog\EbayShadow\Model\Alerts;
use GhostFrog\EbayShadow\Model\Config\Settings;
use GhostFrog\EbayShadow\Model\Report;

class DailySummary
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Report $report,
        private readonly Alerts $alerts
    ) {
    }

    public function execute(): void
    {
        if (!$this->settings->isActive() || !$this->settings->resolve()['alerts/daily_summary']) {
            return;
        }
        $lines = [];
        foreach ($this->report->pots() as $pot) {
            $s = $this->report->summary((int)$pot['pot_id']);
            $lines[] = sprintf('%s: cash £%.2f, tied up £%.2f, bought %d, sold %d, realised £%.2f, banked £%.2f, missed (no cash) £%.2f',
                $pot['name'], $s['cash'], $s['tied_up'], $s['bought'], $s['sold'], $s['realised'], $s['banked'],
                $s['missed_profit']);
        }
        if ($lines !== []) {
            $this->alerts->summary(implode("\n", $lines));
        }
    }
}
