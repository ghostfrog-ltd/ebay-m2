<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Controller\Adminhtml\Pots;

use GhostFrog\EbayShadow\Model\Cash;
use GhostFrog\EbayShadow\Model\Config\Settings;
use GhostFrog\EbayShadow\Model\Config\Source\PotStatus;
use GhostFrog\EbayShadow\Model\PotFactory;
use GhostFrog\EbayShadow\Model\ResourceModel\Pot as PotResource;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Throwable;

/**
 * Creates or updates a pot. Starting capital and start date are fixed once the pot exists, because the
 * capital is a cash movement and later results depend on it.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_EbayShadow::pots';

    public function __construct(
        Context $context,
        private readonly PotFactory $pots,
        private readonly PotResource $resource,
        private readonly Cash $cash
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $data = (array)$this->getRequest()->getPostValue();
        $id = (int)($data['pot_id'] ?? 0);
        $pot = $this->pots->create();
        if ($id > 0) {
            $this->resource->load($pot, $id);
            if (!$pot->getId()) {
                $this->messageManager->addErrorMessage(__('This pot no longer exists.'));
                return $redirect->setPath('*/*/index');
            }
        }

        $name = trim((string)($data['name'] ?? ''));
        $status = (string)($data['status'] ?? PotStatus::RUNNING);
        if ($name === '' || !in_array($status, [PotStatus::RUNNING, PotStatus::PAUSED, PotStatus::CLOSED], true)) {
            $this->messageManager->addErrorMessage(__('Enter a name and a valid status.'));
            return $redirect->setPath('*/*/edit', $id ? ['pot_id' => $id] : []);
        }
        $pot->setData('name', mb_substr($name, 0, 255));
        $pot->setData('status', $status);
        if ($id === 0) {
            $capital = (float)($data['starting_capital'] ?? 0);
            if ($capital <= 0) {
                $this->messageManager->addErrorMessage(__('Starting capital must be above zero.'));
                return $redirect->setPath('*/*/edit');
            }
            $start = strtotime((string)($data['start_date'] ?? '') . ' UTC') ?: time();
            $pot->setData('starting_capital', round($capital, 2));
            $pot->setData('start_date', gmdate('Y-m-d H:i:s', $start));
        }

        $overrides = [];
        foreach (Settings::POT_OVERRIDABLE as $key) {
            $value = trim((string)($data['overrides'][str_replace('/', '__', $key)] ?? ''));
            if ($value !== '') {
                $overrides[$key] = $value;
            }
        }
        $pot->setData('overrides', json_encode((object)$overrides));

        try {
            $this->resource->save($pot);
            $this->cash->ensureCapital((int)$pot->getId(), (float)$pot->getData('starting_capital'),
                (string)$pot->getData('start_date'));
            $this->messageManager->addSuccessMessage(__('The pot was saved.'));
        } catch (Throwable $error) {
            $this->messageManager->addErrorMessage(__('The pot could not be saved: %1', $error->getMessage()));
            return $redirect->setPath('*/*/edit', $id ? ['pot_id' => $id] : []);
        }
        return $this->getRequest()->getParam('back')
            ? $redirect->setPath('*/*/edit', ['pot_id' => $pot->getId()])
            : $redirect->setPath('*/*/index');
    }
}
