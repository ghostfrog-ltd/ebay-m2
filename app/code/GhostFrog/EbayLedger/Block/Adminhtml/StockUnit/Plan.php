<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Block\Adminhtml\StockUnit;

use GhostFrog\EbayLedger\Model\Product\Allocation;
use GhostFrog\EbayLedger\Model\Product\Creator;
use GhostFrog\EbayLedger\Model\ResourceModel\StockUnit as StockUnitResource;
use GhostFrog\EbayLedger\Model\SellPlan\Builder;
use GhostFrog\EbayLedger\Model\SellPlan\Plans;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/** The sell plan page: every option for a received unit, the recommendation and why. */
class Plan extends Template
{
    private ?array $unit = null;
    private ?array $built = null;

    public function __construct(
        Context $context,
        private readonly StockUnitResource $units,
        private readonly Builder $builder,
        private readonly Plans $plans,
        private readonly Creator $creator,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getUnit(): ?array
    {
        if ($this->unit === null) {
            $db = $this->units->getConnection();
            $row = $db->fetchRow($db->select()->from($this->units->getMainTable())
                ->where('stock_unit_id = ?', (int)$this->getRequest()->getParam('stock_unit_id')));
            $this->unit = $row ?: [];
        }
        return $this->unit ?: null;
    }

    public function getInputs(): array
    {
        $unit = $this->getUnit();
        return $unit ? ($this->plans->latest((int)$unit['stock_unit_id'], 'draft')['inputs'] ?? []) : [];
    }

    public function getConfirmed(): ?array
    {
        $unit = $this->getUnit();
        return $unit ? $this->plans->latest((int)$unit['stock_unit_id'], 'confirmed') : null;
    }

    public function getBuilt(): array
    {
        if ($this->built === null) {
            $this->built = $this->builder->build($this->getUnit() ?? [], $this->getInputs());
        }
        return $this->built;
    }

    public function canPlan(): bool
    {
        return in_array($this->getUnit()['status'] ?? '', Plans::PLANNABLE, true);
    }

    /** Products already made from this unit. */
    public function getProducts(): array
    {
        $unit = $this->getUnit();
        return $unit ? $this->creator->active((int)$unit['stock_unit_id']) : [];
    }

    /** SKU and cost share for each sale in an option, keyed by line; empty when a sale has no price yet. */
    public function allocation(array $option): array
    {
        try {
            return Allocation::products($this->getUnit() ?? [], $option);
        } catch (\InvalidArgumentException) {
            return [];
        }
    }

    public function productUrl(int $productId): string
    {
        return $this->getUrl('catalog/product/edit', ['id' => $productId]);
    }

    public function money(?float $value): string
    {
        return $value === null ? '—' : ($value < 0 ? '-£' : '£') . number_format(abs($value), 2);
    }
}
