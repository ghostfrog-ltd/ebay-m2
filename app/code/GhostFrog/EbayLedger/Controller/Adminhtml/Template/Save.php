<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Controller\Adminhtml\Template;

use GhostFrog\EbayLedger\Model\PartsTemplateFactory;
use GhostFrog\EbayLedger\Model\ResourceModel\PartsTemplate as Resource;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Throwable;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_EbayLedger::parts_templates';

    public function __construct(
        Context $context,
        private readonly PartsTemplateFactory $templates,
        private readonly Resource $resource
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $data = (array)$this->getRequest()->getPostValue();
        $id = (int)($data['template_id'] ?? 0);
        $template = $this->templates->create();
        if ($id > 0) {
            $this->resource->load($template, $id);
        }
        $parts = [];
        foreach ((array)($data['parts'] ?? []) as $part) {
            $name = trim((string)($part['name'] ?? ''));
            if ($name === '' || !empty($part['delete'])) {
                continue;
            }
            $estimate = trim(str_replace(['£', ','], '', (string)($part['estimate'] ?? '')));
            $parts[] = [
                'name' => mb_substr($name, 0, 80),
                'group' => mb_substr(trim((string)($part['group'] ?? '')), 0, 80),
                'size_band' => (string)($part['size_band'] ?? 'small'),
                'comp_model_key' => strtolower(trim((string)($part['comp_model_key'] ?? ''))),
                'estimate' => $estimate !== '' && is_numeric($estimate) ? round((float)$estimate, 2) : null,
            ];
        }
        $name = trim((string)($data['name'] ?? ''));
        $keys = trim(strtolower((string)($data['model_keys'] ?? '')));
        if ($name === '' || $keys === '') {
            $this->messageManager->addErrorMessage(__('Enter a name and at least one model key.'));
            return $redirect->setPath('*/*/edit', $id ? ['template_id' => $id] : []);
        }
        $template->addData([
            'name' => mb_substr($name, 0, 100),
            'model_keys' => $keys,
            'whole_size_band' => (string)($data['whole_size_band'] ?? 'large'),
            'active' => (int)!empty($data['active']),
            'parts' => json_encode($parts, JSON_UNESCAPED_UNICODE),
        ]);
        try {
            $this->resource->save($template);
            $this->messageManager->addSuccessMessage(__('The parts template was saved.'));
        } catch (Throwable $error) {
            $this->messageManager->addErrorMessage(__('The template could not be saved: %1', $error->getMessage()));
        }
        return $this->getRequest()->getParam('back')
            ? $redirect->setPath('*/*/edit', ['template_id' => $template->getId()])
            : $redirect->setPath('*/*/index');
    }
}
