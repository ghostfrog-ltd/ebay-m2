<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Purchase;

use GhostFrog\EbayLedger\Model\Ebay\TradingClient;
use GhostFrog\EbayLedger\Model\Ebay\Xml;

/**
 * Purchases from Trading GetOrders with the buyer role. eBay decommissions this call on 30 June 2027; the buy-side
 * GraphQL Order API (scope buy.order.read) replaces it once eBay grants that scope to the keyset.
 */
class TradingPurchases
{
    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 50;

    public function __construct(private readonly TradingClient $client)
    {
    }

    /**
     * Orders created inside the window, which must be at most 90 days long and start within the last 90 days.
     *
     * @return list<array> Orders as arrays.
     */
    public function orders(string $accessToken, string $fromUtc, string $toUtc): array
    {
        $orders = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $xml = $this->client->call($accessToken, 'GetOrders', sprintf(
                '<OrderRole>Buyer</OrderRole><OrderStatus>All</OrderStatus>'
                . '<CreateTimeFrom>%s</CreateTimeFrom><CreateTimeTo>%s</CreateTimeTo>'
                . '<DetailLevel>ReturnAll</DetailLevel>'
                . '<Pagination><EntriesPerPage>%d</EntriesPerPage><PageNumber>%d</PageNumber></Pagination>',
                self::iso($fromUtc), self::iso($toUtc), self::PAGE_SIZE, $page
            ));
            $data = Xml::toArray($xml);
            foreach (Xml::list($data['OrderArray']['Order'] ?? null) as $order) {
                $orders[] = $order;
            }
            if (strtolower((string)Xml::text($data['HasMoreOrders'] ?? null)) !== 'true') {
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
