<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Controller\Adminhtml\StockUnit;

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
        private readonly Plans $plans
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
            $built = $this->builder->build($unit, $inputs);
            $option = (string)$request->getParam('confirm');
            if ($option !== '') {
                $this->plans->confirm($unit, $option, $inputs, $built);
                $this->messageManager->addSuccessMessage(__('%1: plan confirmed: %2.', $unit['reference'],
                    __(Calculator::LABELS[$option] ?? $option)));
            } else {
                $this->plans->saveDraft($id, $inputs, $built);
                $this->messageManager->addSuccessMessage(__('Recalculated.'));
            }
        } catch (Throwable $error) {
            $this->messageManager->addErrorMessage($error->getMessage());
        }
        return $redirect;
    }
}
