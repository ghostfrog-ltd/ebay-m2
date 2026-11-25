<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Block\Adminhtml;

use GhostFrog\Ebay\Model\Dashboard\Stats;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class Dashboard extends Template
{
    private ?array $stats = null;

    public function __construct(Context $context, private readonly Stats $source, array $data = [])
    {
        parent::__construct($context, $data);
    }

    public function getStats(): array
    {
        return $this->stats ??= $this->source->get((bool)$this->getRequest()->getParam('refresh'));
    }

    public function getRefreshUrl(): string
    {
        return $this->getUrl('*/*/index', ['refresh' => 1]);
    }

    public function getLink(string $route): string
    {
        return $this->getUrl($route);
    }
}
