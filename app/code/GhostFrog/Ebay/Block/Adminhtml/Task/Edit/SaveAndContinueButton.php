<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Block\Adminhtml\Task\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class SaveAndContinueButton extends GenericButton implements ButtonProviderInterface
{
    public function getButtonData(): array
    {
        return [
            'label' => __('Save and Continue Edit'),
            'class' => 'save',
            'data_attribute' => [
                'mage-init' => [
                    'buttonAdapter' => [
                        'actions' => [[
                            'targetName' => 'ghostfrog_ebay_task_form.ghostfrog_ebay_task_form',
                            'actionName' => 'save',
                            'params' => [true, ['back' => 'continue']],
                        ]],
                    ],
                ],
            ],
            'sort_order' => 80,
        ];
    }
}
