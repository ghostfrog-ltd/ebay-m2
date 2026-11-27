<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Ebay;

use Magento\Framework\HTTP\Client\CurlFactory;
use RuntimeException;

/** Your sales, read from the Sell Fulfillment API (getOrders only). */
class FulfillmentClient
{
    private const URL = 'https://api.ebay.com/sell/fulfillment/v1/order';
    private const PAGE = 200;
    private const MAX_PAGES = 50;

    public function __construct(private readonly CurlFactory $curlFactory)
    {
    }

    /**
     * @param string $field creationdate or lastmodifieddate
     * @return list<array> Orders as eBay returns them.
     */
    public function orders(string $accessToken, string $field, string $fromUtc, string $toUtc): array
    {
        $filter = sprintf('%s:[%s..%s]', $field, self::iso($fromUtc), self::iso($toUtc));
        $orders = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $client = $this->curlFactory->create();
            $client->setTimeout(30);
            $client->setHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
                'X-EBAY-C-MARKETPLACE-ID' => 'EBAY_GB',
            ]);
            $client->get(self::URL . '?' . http_build_query([
                'filter' => $filter, 'limit' => self::PAGE, 'offset' => $page * self::PAGE,
            ], '', '&', PHP_QUERY_RFC3986));
            $status = (int)$client->getStatus();
            $data = json_decode((string)$client->getBody(), true);
            if ($status !== 200 || !is_array($data)) {
                $message = is_array($data) ? (string)($data['errors'][0]['message'] ?? '') : '';
                throw new RuntimeException(trim("eBay Fulfillment getOrders returned HTTP $status. $message"), $status);
            }
            foreach ($data['orders'] ?? [] as $order) {
                $orders[] = $order;
            }
            if (($page + 1) * self::PAGE >= (int)($data['total'] ?? 0)) {
                break;
            }
        }
        return $orders;
    }

    private static function iso(string $utc): string
    {
        return gmdate('Y-m-d\TH:i:s.000\Z', strtotime($utc . ' UTC'));
    }
}
