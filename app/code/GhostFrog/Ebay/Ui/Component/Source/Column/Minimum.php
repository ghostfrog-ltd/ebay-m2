<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Ui\Component\Source\Column;

use GhostFrog\Ebay\Model\Comps\Pricing;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/** Shows a source's profit or ROI minimum, shipping cost or selling fee, or the global value it falls back to when blank. */
class Minimum extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly Pricing $pricing,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        $name = $this->getData('name');
        $roi = in_array($name, ['min_roi_percent', 'selling_fee_percent'], true);
        $global = match ($name) {
            'shipping' => $this->pricing->shipping(),
            'selling_fee_percent' => $this->pricing->sellingFee(),
            default => $this->pricing->targets()[$roi ? 1 : 0],
        };
        foreach ($dataSource['data']['items'] ?? [] as $index => $item) {
            $value = $item[$name] ?? null;
            $dataSource['data']['items'][$index][$name] = $value === null || $value === ''
                ? (string)__('%1 (global)', $this->format($global, $roi))
                : $this->format((float)$value, $roi);
        }
        return $dataSource;
    }

    private function format(float $value, bool $roi): string
    {
        $number = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
        return $roi ? $number . '%' : '£' . $number;
    }
}
