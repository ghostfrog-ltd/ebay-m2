<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Service;

use GhostFrog\Ebay\Api\SlackInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Throwable;

/** Plain-text Slack messages through the webhook in GhostFrog > eBay Configuration > Slack. */
final class SlackText implements SlackInterface
{
    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly CurlFactory $clients
    ) {
    }

    public function available(): bool
    {
        return $this->url() !== null;
    }

    public function sendText(string $text): bool
    {
        $url = $this->url();
        if ($url === null) {
            return false;
        }
        try {
            $client = $this->clients->create();
            $client->setTimeout(5);
            $client->setHeaders(['Content-Type' => 'application/json']);
            $client->post($url, json_encode(
                ['text' => $text, 'mrkdwn' => false, 'unfurl_links' => false, 'unfurl_media' => false],
                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
            ));
            return (int)$client->getStatus() === 200;
        } catch (Throwable) {
            return false;
        }
    }

    private function url(): ?string
    {
        if (!$this->config->isSetFlag('ghostfrog_ebay/general/enabled')
            || !$this->config->isSetFlag('ghostfrog_ebay/slack/slack_notifications_enabled')
        ) {
            return null;
        }
        $url = trim((string)$this->config->getValue('ghostfrog_ebay/slack/slack_webhook_url'));
        // Incoming webhook URLs contain a credential. Accept only Slack's webhook endpoint.
        return preg_match('~\Ahttps://hooks\.slack(?:-gov)?\.com/services/[A-Za-z0-9_-]+/[A-Za-z0-9_-]+/[A-Za-z0-9_-]+\z~', $url)
            ? $url : null;
    }
}
