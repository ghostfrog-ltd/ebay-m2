<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Finance;

/**
 * Turns one Finances API transaction into fee rows, each a cost (positive) or a credit (negative):
 * the marketplace fees on each sale line, fee credits on refunds, postage labels, promoted listing and other
 * charges, and any other money eBay takes or gives back outside the sale itself.
 */
final class TransactionParser
{
    /** Money for the item and postage; the buyer's side of these is on the Magento order, not a fee. */
    private const ORDER_MONEY = ['SALE', 'REFUND'];

    /** @return list<array<string, mixed>> */
    public static function fees(array $transaction): array
    {
        $type = (string)($transaction['transactionType'] ?? '');
        $id = (string)($transaction['transactionId'] ?? '');
        if ($id === '' || $type === '') {
            return [];
        }
        $orderId = $transaction['orderId'] ?? self::reference($transaction, 'ORDER_ID');
        $base = [
            'transaction_id' => $id,
            'transaction_type' => $type,
            'currency' => (string)($transaction['amount']['currency'] ?? 'GBP'),
            'booked_at' => gmdate('Y-m-d H:i:s', strtotime((string)($transaction['transactionDate'] ?? '')) ?: time()),
            'ebay_order_id' => $orderId === null ? null : (string)$orderId,
            'payout_id' => isset($transaction['payoutId']) ? (string)$transaction['payoutId'] : null,
        ];
        // A refund gives fees back, so its fee lines are credits.
        $sign = $type === 'REFUND' ? -1 : 1;
        $rows = [];
        foreach ($transaction['orderLineItems'] ?? [] as $line) {
            $lineId = isset($line['lineItemId']) ? (string)$line['lineItemId'] : null;
            foreach ($line['marketplaceFees'] ?? [] as $index => $fee) {
                $amount = round((float)($fee['amount']['value'] ?? 0), 2);
                if (abs($amount) < 0.005) {
                    continue;
                }
                $feeType = (string)($fee['feeType'] ?? 'OTHER_FEES');
                $rows[] = $base + [
                    'fee_key' => self::key($type, $id, $lineId, $feeType, $index),
                    'fee_type' => $feeType,
                    'amount' => $sign * $amount,
                    'ebay_line_item_id' => $lineId,
                ];
            }
        }
        if (!in_array($type, self::ORDER_MONEY, true) && $rows === []) {
            $amount = round((float)($transaction['amount']['value'] ?? 0), 2);
            if (abs($amount) >= 0.005) {
                // A debit costs you; a credit (a fee refund, a dispute won) gives back.
                $signed = ($transaction['bookingEntry'] ?? 'DEBIT') === 'CREDIT' ? -$amount : $amount;
                $feeType = (string)($transaction['feeType'] ?? $type);
                $rows[] = $base + [
                    'fee_key' => self::key($type, $id, null, $feeType, 0),
                    'fee_type' => $feeType,
                    'amount' => $signed,
                    'ebay_line_item_id' => self::reference($transaction, 'LINE_ITEM_ID'),
                ];
            }
        }
        return $rows;
    }

    /** For a sale: the order, what eBay credited you for it and the payout that carried it. */
    public static function saleCredit(array $transaction): ?array
    {
        if (($transaction['transactionType'] ?? '') !== 'SALE' || empty($transaction['orderId'])) {
            return null;
        }
        return [
            'transaction_id' => (string)$transaction['transactionId'],
            'ebay_order_id' => (string)$transaction['orderId'],
            'payout_id' => isset($transaction['payoutId']) ? (string)$transaction['payoutId'] : null,
            'net_amount' => round((float)($transaction['amount']['value'] ?? 0), 2),
            'booked_at' => gmdate('Y-m-d H:i:s', strtotime((string)($transaction['transactionDate'] ?? '')) ?: time()),
        ];
    }

    private static function reference(array $transaction, string $type): ?string
    {
        foreach ($transaction['references'] ?? [] as $reference) {
            if (($reference['referenceType'] ?? '') === $type && !empty($reference['referenceId'])) {
                return (string)$reference['referenceId'];
            }
        }
        return null;
    }

    private static function key(string $type, string $id, ?string $line, string $feeType, int $index): string
    {
        return mb_substr(implode('|', [$type, $id, $line ?? '-', $feeType, $index]), 0, 190);
    }
}
