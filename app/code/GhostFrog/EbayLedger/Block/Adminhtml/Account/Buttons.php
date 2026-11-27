<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Block\Adminhtml\Account;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/** Base for the account form's buttons. */
abstract class Buttons implements ButtonProviderInterface
{
    public function __construct(protected readonly UrlInterface $url, protected readonly RequestInterface $request)
    {
    }

    protected function accountId(): int
    {
        return (int)$this->request->getParam('account_id');
    }

    protected function go(string $path, array $params = []): string
    {
        return sprintf("location.href = '%s';", $this->url->getUrl($path, $params));
    }
}
