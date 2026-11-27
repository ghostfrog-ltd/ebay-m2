<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Block\Adminhtml\Dashboard;

use GhostFrog\EbayLedger\Model\Profit\Calculator;
use GhostFrog\EbayLedger\Model\Profit\Report;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class Profit extends Template
{
    private ?array $data = null;

    public function __construct(Context $context, private readonly Report $report, array $data = [])
    {
        parent::__construct($context, $data);
    }

    public function data(): array
    {
        if ($this->data === null) {
            $products = $this->report->products();
            $units = $this->report->units($products);
            $sold = array_values(array_filter($products, static fn($p) => $p['sold']));
            $this->data = [
                'products' => $products,
                'sold' => $sold,
                'units' => $units,
                'totals' => $this->report->totals($products, $units),
                'grades' => $this->report->grades($units),
                'profit' => round(array_sum(array_column($sold, 'profit'))
                    + array_sum(array_map(static fn($u) => in_array($u['status'], ['returned_to_seller', 'written_off'], true) ? (float)$u['profit'] : 0.0, $units)), 2),
                'income' => round(array_sum(array_column($sold, 'income')), 2),
                'tied_up' => round(array_sum(array_column($units, 'unsold_cost')), 2),
                'days' => [
                    'Bought to received' => Calculator::average(array_column($units, 'days_to_receive')),
                    'Received to planned' => Calculator::average(array_column($units, 'days_to_plan')),
                    'Listed to sold' => Calculator::average(array_column($units, 'days_to_sell')),
                    'Sold to paid out' => Calculator::average(array_column($units, 'days_to_payout')),
                ],
            ];
        }
        return $this->data;
    }

    public function money(?float $value): string
    {
        return $value === null ? '—' : Calculator::money($value);
    }
}
