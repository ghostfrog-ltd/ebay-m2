<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Controller\Oauth;

use GhostFrog\EbayLedger\Model\Account\ConsentState;
use GhostFrog\EbayLedger\Model\Account\Tokens;
use GhostFrog\EbayLedger\Model\Config\Settings;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * eBay sends the browser here after you sign in and allow access (the RuName's accept URL). Only a state value
 * that the admin Connect button issued in the last 15 minutes is accepted, and only once.
 */
class Callback implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly ConsentState $state,
        private readonly Tokens $tokens,
        private readonly Settings $settings,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly UrlInterface $url,
        private readonly Escaper $escaper,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): Raw
    {
        $accountId = $this->state->consume((string)$this->request->getParam('state'));
        $code = (string)$this->request->getParam('code');
        if ($accountId === null) {
            return $this->page(false, 'This sign-in link has expired or was already used. Go back to the account in Magento and click Connect to eBay again.');
        }
        if ($code === '') {
            return $this->page(false, 'eBay did not grant access, so nothing was connected.');
        }
        try {
            $account = $this->tokens->load($accountId);
            $this->tokens->connect($account, $code, $this->settings->scopes());
            $name = $account->getData('ebay_username') ?: $account->getData('label');
            return $this->page(true, sprintf('Connected eBay account %s.', $name), $accountId);
        } catch (Throwable $error) {
            $this->logger->warning('GhostFrog Ledger could not connect an eBay account.', [
                'account_id' => $accountId, 'error' => $error->getMessage(),
            ]);
            return $this->page(false, 'eBay sign-in did not complete: ' . $error->getMessage(), $accountId);
        }
    }

    private function page(bool $ok, string $message, ?int $accountId = null): Raw
    {
        $admin = rtrim($this->url->getBaseUrl(['_type' => UrlInterface::URL_TYPE_WEB]), '/') . '/'
            . trim((string)$this->deploymentConfig->get('backend/frontName'), '/') . '/ghostfrog_ledger/account/'
            . ($accountId ? 'edit/account_id/' . $accountId . '/' : 'index/');
        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex">'
            . '<title>GhostFrog Ledger</title><style>body{font:16px/1.5 system-ui,sans-serif;max-width:40em;margin:4em auto;padding:0 1em}'
            . '.ok{color:#1a7f37}.err{color:#b42318}</style></head><body><h1 class="' . ($ok ? 'ok' : 'err') . '">'
            . ($ok ? 'Connected' : 'Not connected') . '</h1><p>' . $this->escaper->escapeHtml($message) . '</p>'
            . '<p><a href="' . $this->escaper->escapeUrl($admin) . '">Back to the account in Magento</a></p></body></html>';
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/html; charset=utf-8', true);
        $result->setHeader('Cache-Control', 'no-store', true);
        $result->setHeader('Referrer-Policy', 'no-referrer', true);
        return $result->setContents($html);
    }
}
