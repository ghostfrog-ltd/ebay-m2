<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Sale;

/** One Fulfillment API order as sale lines. Only IDs, the custom label, amounts and statuses are kept. */
final class SaleParser
{
    /** @return list<array<string, mixed>> */
    public static function lines(array $order): array
    {
        $orderId = (string)($order['orderId'] ?? '');
        if ($orderId === '') {
            return [];
        }
        $soldAt = strtotime((string)($order['creationDate'] ?? '')) ?: time();
        $cancelled = ($order['cancelStatus']['cancelState'] ?? '') === 'CANCELED';
        $lines = [];
        foreach ($order['lineItems'] ?? [] as $line) {
            $refunded = 0.0;
            foreach ($line['refunds'] ?? [] as $refund) {
                $refunded += (float)($refund['amount']['value'] ?? 0);
            }
            $sku = trim((string)($line['sku'] ?? ''));
            $lines[] = [
                'ebay_order_id' => $orderId,
                'ebay_line_item_id' => (string)($line['lineItemId'] ?? ''),
                'ebay_item_id' => isset($line['legacyItemId']) ? (string)$line['legacyItemId'] : null,
                'sku' => $sku === '' ? null : mb_substr($sku, 0, 80),
                'title' => mb_substr((string)($line['title'] ?? ''), 0, 255),
                'quantity' => max(1, (int)($line['quantity'] ?? 1)),
                'currency' => (string)($line['lineItemCost']['currency'] ?? $order['pricingSummary']['total']['currency'] ?? 'GBP'),
                'line_price' => round((float)($line['lineItemCost']['value'] ?? 0), 2),
                'postage' => round((float)($line['deliveryCost']['shippingCost']['value'] ?? 0), 2),
                'refunded' => round($refunded, 2),
                'sold_at' => gmdate('Y-m-d H:i:s', $soldAt),
                'payment_status' => $order['orderPaymentStatus'] ?? null,
                'fulfillment_status' => $line['lineItemFulfillmentStatus'] ?? ($order['orderFulfillmentStatus'] ?? null),
                'cancelled' => $cancelled ? 1 : 0,
            ];
        }
        return $lines;
    }
}
