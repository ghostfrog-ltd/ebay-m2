<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Block\Adminhtml;

use GhostFrog\EbayShadow\Model\Report;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/** Server-rendered dashboard. Charts are inline SVG with no external libraries. */
class Dashboard extends Template
{
    private ?array $pots = null;

    public function __construct(Context $context, private readonly Report $report, array $data = [])
    {
        parent::__construct($context, $data);
    }

    public function report(): Report
    {
        return $this->report;
    }

    public function pots(): array
    {
        return $this->pots ??= $this->report->pots();
    }

    public function selectedPot(): ?array
    {
        $id = (int)$this->getRequest()->getParam('pot_id');
        foreach ($this->pots() as $pot) {
            if ((int)$pot['pot_id'] === $id || ($id === 0 && $pot['status'] === 'running')) {
                return $pot;
            }
        }
        return $this->pots()[0] ?? null;
    }

    public function potUrl(int $potId): string
    {
        return $this->getUrl('*/*/index', ['pot_id' => $potId]);
    }

    public function money(?float $value): string
    {
        return $value === null ? '-' : ($value < 0 ? '-£' : '£') . number_format(abs($value), 2);
    }

    /** Line chart of a date => value series. */
    public function lineChart(array $series, int $width = 720, int $height = 200): string
    {
        if (count($series) < 2) {
            $series = $series === [] ? [] : [array_key_first($series) => reset($series)] + $series;
            if ($series === []) {
                return '';
            }
        }
        $values = array_values($series);
        $min = min(0.0, min($values));
        $max = max($values) ?: 1.0;
        $pad = 30;
        $n = max(1, count($values) - 1);
        $points = [];
        foreach ($values as $i => $value) {
            $x = $pad + ($width - 2 * $pad) * $i / $n;
            $y = $height - $pad - ($height - 2 * $pad) * ($value - $min) / (($max - $min) ?: 1);
            $points[] = round($x, 1) . ',' . round($y, 1);
        }
        $keys = array_keys($series);
        $label = static fn(string $text, float $x, float $y, string $anchor = 'start'): string =>
            sprintf('<text x="%s" y="%s" font-size="11" fill="#666" text-anchor="%s">%s</text>', $x, $y, $anchor,
                htmlspecialchars($text, ENT_QUOTES));
        return sprintf('<svg viewBox="0 0 %d %d" width="100%%" height="%d" role="img" aria-label="Cash balance over time">', $width, $height, $height)
            . sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="#ccc"/>', $pad, $height - $pad, $width - $pad, $height - $pad)
            . sprintf('<polyline fill="none" stroke="#1979c3" stroke-width="2" points="%s"/>', implode(' ', $points))
            . $label($this->money($max), 2, $pad - 6) . $label($this->money($min), 2, $height - $pad + 14)
            . $label($keys[0], $pad, $height - 4) . $label(end($keys), $width - $pad, $height - 4, 'end')
            . '</svg>';
    }

    /** Horizontal bar chart of label => value. */
    public function barChart(array $bars, int $width = 720): string
    {
        $max = max([1.0, ...array_values($bars)]);
        $row = 26;
        $svg = sprintf('<svg viewBox="0 0 %d %d" width="100%%" height="%d" role="img" aria-label="Cash tied up by stage">',
            $width, $row * count($bars) + 6, $row * count($bars) + 6);
        $i = 0;
        foreach ($bars as $label => $value) {
            $y = $i++ * $row + 4;
            $w = ($width - 260) * $value / $max;
            $svg .= sprintf('<text x="0" y="%d" font-size="12" fill="#333">%s</text>', $y + 15, htmlspecialchars((string)$label, ENT_QUOTES))
                . sprintf('<rect x="110" y="%d" width="%s" height="18" fill="#eb5202" opacity=".8"/>', $y, round(max(0, $w), 1))
                . sprintf('<text x="%s" y="%d" font-size="12" fill="#333">%s</text>', round(116 + max(0, $w), 1), $y + 14,
                    htmlspecialchars($this->money($value), ENT_QUOTES));
        }
        return $svg . '</svg>';
    }
}
