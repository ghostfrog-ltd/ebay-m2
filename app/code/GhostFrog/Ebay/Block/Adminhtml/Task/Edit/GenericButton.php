<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Block\Adminhtml\Task\Edit;

use Magento\Backend\Block\Widget\Context;

abstract class GenericButton
{
    public function __construct(protected readonly Context $context)
    {
    }

    protected function taskId(): int
    {
        return (int)$this->context->getRequest()->getParam('task_id');
    }

    protected function url(string $route, array $params = []): string
    {
        return $this->context->getUrlBuilder()->getUrl($route, $params);
    }
}
