<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Block\Adminhtml\Task\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class DeleteButton extends GenericButton implements ButtonProviderInterface
{
    public function getButtonData(): array
    {
        if ($this->taskId() <= 0) {
            return [];
        }
        $escaper = $this->context->getEscaper();
        $message = $escaper->escapeJs($escaper->escapeHtml(__('Delete this task? This cannot be undone.')));
        return [
            'label' => __('Delete Task'),
            'class' => 'delete',
            'on_click' => sprintf(
                "deleteConfirm('%s', '%s', {\"data\": {}})",
                $message,
                $this->url('*/*/delete', ['task_id' => $this->taskId()])
            ),
            'sort_order' => 20,
        ];
    }
}
