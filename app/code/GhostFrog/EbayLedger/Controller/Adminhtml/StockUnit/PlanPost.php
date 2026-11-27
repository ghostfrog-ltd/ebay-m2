<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Controller\Adminhtml\StockUnit;

use GhostFrog\EbayLedger\Model\Product\Allocation;
use GhostFrog\EbayLedger\Model\Product\Creator;
use GhostFrog\EbayLedger\Model\ResourceModel\StockUnit as StockUnitResource;
use GhostFrog\EbayLedger\Model\SellPlan\Builder;
use GhostFrog\EbayLedger\Model\SellPlan\Calculator;
use GhostFrog\EbayLedger\Model\SellPlan\Plans;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Throwable;

/** Recalculates the plan with your ticks and prices, or confirms one option. */
class PlanPost extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_EbayLedger::products';

    public function __construct(
        Context $context,
        private readonly StockUnitResource $units,
        private readonly Builder $builder,
        private readonly Plans $plans,
        private readonly Creator $creator
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $request = $this->getRequest();
        $id = (int)$request->getParam('stock_unit_id');
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/plan', ['stock_unit_id' => $id]);
        $db = $this->units->getConnection();
        $unit = $db->fetchRow($db->select()->from($this->units->getMainTable())->where('stock_unit_id = ?', $id));
        if (!$unit) {
            $this->messageManager->addErrorMessage(__('This stock unit no longer exists.'));
            return $redirect->setPath('*/*/index');
        }
        $inputs = [];
        if (is_array($request->getParam('overrides'))) {
            $inputs['present'] = array_values(array_map('strval', (array)$request->getParam('present', [])));
            $inputs['overrides'] = [];
            foreach ($request->getParam('overrides') as $name => $value) {
                $value = trim(str_replace(['£', ','], '', (string)$value));
                if ($value !== '' && is_numeric($value) && (float)$value > 0) {
                    $inputs['overrides'][(string)$name] = round((float)$value, 2);
                }
            }
        }
        try {
            if ($request->getParam('do') === 'create_products') {
                return $this->createProducts($unit, false, $redirect);
            }
            $built = $this->builder->build($unit, $inputs);
            $option = (string)$request->getParam('confirm');
            if ($option === '') {
                $this->plans->saveDraft($id, $inputs, $built);
                $this->messageManager->addSuccessMessage(__('Recalculated.'));
                return $redirect;
            }
            if (!isset($built['result']['options'][$option])) {
                throw new \InvalidArgumentException('That option is not available for this item.');
            }
            // Check every sale has a price, and that you agreed to replace any existing products, before confirming.
            Allocation::products($unit, $built['result']['options'][$option]);
            $replace = (bool)$request->getParam('replace');
            if ($this->creator->active($id) !== [] && !$replace) {
                throw new \InvalidArgumentException('This unit already has products. Check the new SKUs and cost shares, tick "Replace the products above" and confirm again.');
            }
            $this->plans->confirm($unit, $option, $inputs, $built);
            $this->messageManager->addSuccessMessage(__('%1: plan confirmed: %2.', $unit['reference'],
                __(Calculator::LABELS[$option] ?? $option)));
            return $this->createProducts($unit, $replace, $redirect);
        } catch (Throwable $error) {
            $this->messageManager->addErrorMessage($error->getMessage());
        }
        return $redirect;
    }

    private function createProducts(array $unit, bool $replace, Redirect $redirect): Redirect
    {
        $plan = $this->plans->latest((int)$unit['stock_unit_id'], 'confirmed');
        if ($plan === null) {
            $this->messageManager->addErrorMessage(__('Confirm a plan first.'));
            return $redirect;
        }
        try {
            $created = $this->creator->create($unit, $plan, $replace);
            $this->messageManager->addSuccessMessage(__('Created %1 product(s): %2. Paste each SKU into its eBay listing\'s Custom label (SKU).',
                count($created), implode(', ', array_column($created, 'sku'))));
        } catch (Throwable $error) {
            $this->messageManager->addErrorMessage(__('The plan is confirmed but the products were not created: %1', $error->getMessage()));
        }
        return $redirect;
    }
}
