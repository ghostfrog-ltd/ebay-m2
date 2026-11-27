<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Ebay;

use Magento\Framework\HTTP\Client\CurlFactory;
use RuntimeException;
use SimpleXMLElement;

/** Read-only Trading API calls made with an account's OAuth user token. */
class TradingClient
{
    private const URL = 'https://api.ebay.com/ws/api.dll';
    private const SITE_UK = '3';
    private const COMPATIBILITY = '1487';
    /** Calls Ledger may make. Nothing that buys, bids, offers or lists. */
    private const READ_CALLS = ['GetOrders', 'GetMyeBayBuying'];

    public function __construct(private readonly CurlFactory $curlFactory)
    {
    }

    /**
     * @param string $innerXml Request elements inside the <{Call}Request> root.
     * @return SimpleXMLElement The response, with Ack Success or Warning.
     */
    public function call(string $accessToken, string $callName, string $innerXml): SimpleXMLElement
    {
        if (!in_array($callName, self::READ_CALLS, true)) {
            throw new RuntimeException("Ledger does not make the Trading call $callName.");
        }
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . "<{$callName}Request xmlns=\"urn:ebay:apis:eBLBaseComponents\">"
            . '<ErrorLanguage>en_GB</ErrorLanguage><WarningLevel>High</WarningLevel>'
            . $innerXml . "</{$callName}Request>";
        $client = $this->curlFactory->create();
        $client->setTimeout(30);
        $client->setOption(CURLOPT_CONNECTTIMEOUT, 10);
        $client->setHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'X-EBAY-API-CALL-NAME' => $callName,
            'X-EBAY-API-SITEID' => self::SITE_UK,
            'X-EBAY-API-COMPATIBILITY-LEVEL' => self::COMPATIBILITY,
            'X-EBAY-API-IAF-TOKEN' => $accessToken,
        ]);
        $client->post(self::URL, $body);
        $status = (int)$client->getStatus();
        if ($status !== 200) {
            throw new RuntimeException("eBay $callName returned HTTP $status.", $status);
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string((string)$client->getBody(), SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_use_internal_errors($previous);
        }
        if (!$xml instanceof SimpleXMLElement) {
            throw new RuntimeException("eBay $callName returned a response that is not XML.");
        }
        $ack = (string)$xml->Ack;
        if ($ack !== 'Success' && $ack !== 'Warning') {
            $messages = [];
            foreach ($xml->Errors as $error) {
                if ((string)$error->SeverityCode === 'Error') {
                    $messages[] = trim((string)$error->ErrorCode . ' ' . (string)$error->ShortMessage);
                }
            }
            throw new RuntimeException("eBay $callName failed: " . (implode('; ', $messages) ?: $ack));
        }
        return $xml;
    }
}
