<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Purchase;

use GhostFrog\EbayLedger\Model\Ebay\Xml;
use GhostFrog\EbayLedger\Model\Money;

/**
 * Turns one Trading GetOrders order (buyer role, as an array) into stock units: one per item bought, so a line
 * with quantity 2 gives two units. Postage and other charges on the order are shared out to the penny.
 */
final class OrderParser
{
    public const METHOD_AUCTION = 'auction';
    public const METHOD_BUY_IT_NOW = 'buy_it_now';
    public const METHOD_BEST_OFFER = 'best_offer';
    public const METHOD_UNKNOWN = 'unknown';

    /** @return list<array<string, mixed>> */
    public static function units(array $order): array
    {
        $orderId = Xml::text($order['OrderID'] ?? null);
        $transactions = Xml::list($order['TransactionArray']['Transaction'] ?? null);
        if ($orderId === null || $transactions === []) {
            return [];
        }

        $lines = [];
        foreach ($transactions as $index => $transaction) {
            $quantity = max(1, (int)(Xml::text($transaction['QuantityPurchased'] ?? null) ?? 1));
            $unitPrice = Xml::amount($transaction['TransactionPrice'] ?? null) ?? 0.0;
            $lines[$index] = [
                'transaction' => $transaction,
                'quantity' => $quantity,
                'items' => round($unitPrice * $quantity, 2),
                'postage' => Xml::amount($transaction['ActualShippingCost'] ?? null)
                    ?? Xml::amount($transaction['ShippingServiceSelected']['ShippingServiceCost'] ?? null),
            ];
        }

        $weights = array_map(static fn(array $line) => $line['items'], $lines);
        $orderPostage = Xml::amount($order['ShippingServiceSelected']['ShippingServiceCost'] ?? null) ?? 0.0;
        if (in_array(null, array_column($lines, 'postage'), true)) {
            $shares = count($lines) === 1 ? [array_key_first($lines) => $orderPostage] : Money::split($orderPostage, $weights);
            foreach ($lines as $index => $line) {
                $lines[$index]['postage'] = $shares[$index];
            }
        }

        // Whatever the order total holds beyond items and postage, such as eBay's buyer protection fee.
        $total = Xml::amount($order['Total'] ?? null) ?? Xml::amount($order['AmountPaid'] ?? null);
        $known = array_sum(array_column($lines, 'items')) + array_sum(array_column($lines, 'postage'));
        $extra = $total === null ? 0.0 : round($total - $known, 2);
        $extraShares = abs($extra) < 0.005 ? array_map(static fn() => 0.0, $lines) : Money::split($extra, $weights);

        $currency = Xml::currency($order['Total'] ?? null) ?? Xml::currency($order['AmountPaid'] ?? null) ?? 'GBP';
        $created = self::date($order['CreatedTime'] ?? null);
        $paid = self::date($order['PaidTime'] ?? null);
        $orderShipped = self::date($order['ShippedTime'] ?? null);
        $status = Xml::text($order['OrderStatus'] ?? null);

        $units = [];
        foreach ($lines as $index => $line) {
            $transaction = $line['transaction'];
            $item = $transaction['Item'] ?? [];
            $lineId = Xml::text($transaction['OrderLineItemID'] ?? null)
                ?? (Xml::text($item['ItemID'] ?? null) . '-' . Xml::text($transaction['TransactionID'] ?? null));
            $weightsPerUnit = array_fill(1, $line['quantity'], 1);
            $prices = Money::split($line['items'], $weightsPerUnit);
            $postages = Money::split($line['postage'], $weightsPerUnit);
            $others = Money::split($extraShares[$index], $weightsPerUnit);
            for ($unit = 1; $unit <= $line['quantity']; $unit++) {
                $units[] = [
                    'ebay_order_id' => $orderId,
                    'ebay_line_item_id' => $lineId,
                    'ebay_transaction_id' => Xml::text($transaction['TransactionID'] ?? null),
                    'ebay_item_id' => (string)Xml::text($item['ItemID'] ?? null),
                    'unit_index' => $unit,
                    'line_quantity' => $line['quantity'],
                    'title' => mb_substr((string)(Xml::text($item['Title'] ?? null) ?? ''), 0, 255),
                    'currency' => $currency,
                    'item_price' => $prices[$unit],
                    'postage_paid' => $postages[$unit],
                    'other_costs' => $others[$unit],
                    'total_cost' => round($prices[$unit] + $postages[$unit] + $others[$unit], 2),
                    'purchase_method' => $method = self::method($transaction),
                    'purchase_method_source' => $method === self::METHOD_UNKNOWN ? null : 'ebay',
                    'purchased_at' => self::date($transaction['CreatedDate'] ?? null) ?? $created ?? gmdate('Y-m-d H:i:s'),
                    'paid_at' => self::date($transaction['PaidTime'] ?? null) ?? $paid,
                    'shipped_at' => self::date($transaction['ShippedTime'] ?? null) ?? $orderShipped,
                    'ebay_order_status' => $status,
                ];
            }
        }
        return $units;
    }

    /** How the item was bought, from the fields eBay gives a buyer; unknown when it gives none. */
    public static function method(array $transaction): string
    {
        $bestOffer = strtolower((string)Xml::text($transaction['BestOfferSale'] ?? null));
        if ($bestOffer === 'true') {
            return self::METHOD_BEST_OFFER;
        }
        $type = Xml::text($transaction['Item']['ListingType'] ?? null);
        return match ($type) {
            'Chinese' => self::METHOD_AUCTION,
            'FixedPriceItem', 'StoresFixedPrice' => self::METHOD_BUY_IT_NOW,
            default => self::METHOD_UNKNOWN,
        };
    }

    private static function date(mixed $value): ?string
    {
        $text = Xml::text($value);
        $time = $text === null ? false : strtotime($text);
        return $time === false ? null : gmdate('Y-m-d H:i:s', $time);
    }
}
