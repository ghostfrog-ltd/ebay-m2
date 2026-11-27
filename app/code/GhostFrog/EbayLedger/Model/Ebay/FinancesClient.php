<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Ebay;

use Magento\Framework\HTTP\Client\CurlFactory;
use RuntimeException;

/** Your eBay money movements (Sell Finances API getTransactions and getPayouts), signed as eBay requires. */
class FinancesClient
{
    private const BASE = 'https://apiz.ebay.com/sell/finances/v1/';
    private const MAX_PAGES = 50;

    public function __construct(private readonly CurlFactory $curlFactory, private readonly SigningKeys $keys)
    {
    }

    /** @return list<array> */
    public function transactions(string $accessToken, string $fromUtc, string $toUtc): array
    {
        return $this->pages($accessToken, 'transaction', 'transactions', 'transactionDate', $fromUtc, $toUtc, 1000);
    }

    /** @return list<array> */
    public function payouts(string $accessToken, string $fromUtc, string $toUtc): array
    {
        return $this->pages($accessToken, 'payout', 'payouts', 'payoutDate', $fromUtc, $toUtc, 200);
    }

    private function pages(string $token, string $resource, string $key, string $field, string $from, string $to, int $limit): array
    {
        $rows = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $url = self::BASE . $resource . '?' . http_build_query([
                'filter' => sprintf('%s:[%s..%s]', $field, self::iso($from), self::iso($to)),
                'limit' => $limit,
                'offset' => $page * $limit,
            ], '', '&', PHP_QUERY_RFC3986);
            $client = $this->curlFactory->create();
            $client->setTimeout(30);
            $client->setHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'X-EBAY-C-MARKETPLACE-ID' => 'EBAY_GB',
            ] + $this->keys->sign('GET', $url));
            $client->get($url);
            $status = (int)$client->getStatus();
            $data = json_decode((string)$client->getBody(), true);
            if ($status !== 200 || !is_array($data)) {
                $message = is_array($data) ? (string)($data['errors'][0]['message'] ?? '') : '';
                throw new RuntimeException(trim("eBay Finances $resource returned HTTP $status. $message"), $status);
            }
            foreach ($data[$key] ?? [] as $row) {
                $rows[] = $row;
            }
            if (($page + 1) * $limit >= (int)($data['total'] ?? 0)) {
                break;
            }
        }
        return $rows;
    }

    private static function iso(string $utc): string
    {
        return gmdate('Y-m-d\TH:i:s.000\Z', strtotime($utc . ' UTC'));
    }
}
