<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Enrichment;

use GhostFrog\Ebay\Model\Api\DailyQuota;
use GhostFrog\Ebay\Model\Api\Usage;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use RuntimeException;

final class TradingClient
{
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly ScopeConfigInterface $config,
        private readonly Usage $usage,
        private readonly DailyQuota $quota,
        private readonly TradingResponse $response
    ) {
    }

    public function configured(): bool
    {
        return $this->token() !== '';
    }

    public function getItem(string $externalId): array
    {
        return $this->fetch($externalId, false);
    }

    public function getSaleOutcome(string $externalId): array
    {
        return $this->fetch($externalId, true);
    }

    private function fetch(string $externalId, bool $saleOutcome): array
    {
        if (!preg_match('/\A(?:v[12]\|)?([0-9]{1,20})(?:\|[0-9]+)?\z/', $externalId, $parts)) {
            throw new RuntimeException('Invalid eBay item ID.', 422);
        }
        $itemId = $parts[1];
        $token = $this->token();
        if ($token === '') {
            throw new RuntimeException('Configure the eBay Trading user token in Magento.', 401);
        }
        $client = $this->curlFactory->create();
        $client->setTimeout(8);
        $client->setOption(CURLOPT_CONNECTTIMEOUT, 4);
        $client->setHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'X-EBAY-API-CALL-NAME' => 'GetItem',
            'X-EBAY-API-SITEID' => '3',
            'X-EBAY-API-COMPATIBILITY-LEVEL' => '1487',
            'X-EBAY-API-IAF-TOKEN' => $token,
        ]);
        $body = <<<XML
            <?xml version="1.0" encoding="utf-8"?>
            <GetItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">
                <ItemID>{$itemId}</ItemID>
                <DetailLevel>ReturnAll</DetailLevel>
                <IncludeItemSpecifics>true</IncludeItemSpecifics>
            </GetItemRequest>
            XML;
        $this->quota->refreshIfDue(Usage::TRADING);
        // A timeout can still consume a provider call, so count each attempt before sending it.
        $this->usage->send(
            $saleOutcome ? 'ebay_ended_v1' : Usage::ATTRIBUTES,
            static fn () => $client->post('https://api.ebay.com/ws/api.dll', $body)
        );
        $status = (int)$client->getStatus();
        if ($status === 429) {
            throw $this->usage->recordThrottle(Usage::TRADING, $client->getHeaders());
        }
        if ($status !== 200) {
            throw new RuntimeException("eBay GetItem returned HTTP {$status}.", $status);
        }
        try {
            return $this->response->parse($client->getBody(), $itemId, $saleOutcome);
        } catch (RuntimeException $exception) {
            if ($exception->getCode() === 429) {
                throw $this->usage->recordThrottle(Usage::TRADING, $client->getHeaders());
            }
            throw $exception;
        }
    }

    private function token(): string
    {
        return trim((string)$this->config->getValue('ghostfrog_ebay/ebay_api/ebay_trading_token'));
    }
}
