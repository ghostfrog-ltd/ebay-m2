<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\Engine;

/**
 * Date arithmetic for a shadow item's stages. Pure: no I/O. Days may be fractional.
 *
 * bought -> received (seller dispatch + delivery) -> listed (my listing days) -> sold (days to sell)
 * -> delivered (my dispatch + delivery to buyer) -> paid out (payout delay) and banked (return window).
 */
final class Lifecycle
{
    public const STAGES = [
        'bidding', 'lost', 'void', 'inbound', 'received', 'listed', 'outbound', 'paid_out', 'banked', 'written_off',
    ];
    /** Stages where the item's cash is still committed. Outbound lasts from the sale until the payout. */
    public const OPEN = ['bidding', 'inbound', 'received', 'listed', 'outbound'];
    /** Stages an item can still move on from. */
    public const ACTIVE = ['bidding', 'inbound', 'received', 'listed', 'outbound', 'paid_out'];

    /**
     * The stage a won item has reached at $now from its simulated times (Unix, or null when not yet known).
     *
     * @param array{received_at: ?int, listed_at: ?int, sold_at: ?int, paid_out_at: ?int, banked_at: ?int} $times
     */
    public static function stageAt(array $times, int $now): string
    {
        foreach (['banked_at' => 'banked', 'paid_out_at' => 'paid_out', 'sold_at' => 'outbound',
            'listed_at' => 'listed', 'received_at' => 'received'] as $field => $stage) {
            if ($times[$field] !== null && $times[$field] <= $now) {
                return $stage;
            }
        }
        return 'inbound';
    }

    public static function inboundDays(array $settings): float
    {
        $delivery = $settings['inbound/delivery_days']['default'] ?? 2;
        return (float)$settings['inbound/seller_dispatch_days'] + (float)$delivery;
    }

    /** @return array{received: int, listed: int} Unix times. */
    public static function afterPurchase(array $settings, int $boughtAt): array
    {
        $received = self::add($boughtAt, self::inboundDays($settings));
        return ['received' => $received, 'listed' => self::add($received, (float)$settings['outbound/listing_days'])];
    }

    /** @return array{delivered: int, paid_out: int, banked: int} Unix times. */
    public static function afterSale(array $settings, int $soldAt): array
    {
        $delivered = self::add($soldAt, (float)$settings['outbound/dispatch_days'] + (float)$settings['outbound/delivery_days']);
        return [
            'delivered' => $delivered,
            'paid_out' => self::add($delivered, (float)$settings['costs/payout_delay_days']),
            'banked' => self::add($delivered, (float)$settings['costs/return_window_days']),
        ];
    }

    /** Whole planned cycle at purchase, from the predicted days to sell. @return array<string, int> */
    public static function plan(array $settings, int $boughtAt, float $daysToSell): array
    {
        $before = self::afterPurchase($settings, $boughtAt);
        $sold = self::add($before['listed'], $daysToSell);
        return $before + ['sold' => $sold] + self::afterSale($settings, $sold);
    }

    public static function add(int $time, float $days): int
    {
        return $time + (int)round($days * 86400);
    }
}
