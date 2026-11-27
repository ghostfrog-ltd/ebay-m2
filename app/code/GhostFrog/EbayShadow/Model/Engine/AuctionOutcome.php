<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\Engine;

/**
 * Settles a shadow max bid against what really happened to the auction. Pure: no I/O.
 *
 * Our bid was never placed, so it could not change the real result. When the real final price is at or below
 * our max bid we treat the auction as won at one eBay UK increment above that price, capped at our max bid.
 * Had we bid, the real winner might have bid higher still, so this is an approximation.
 */
final class AuctionOutcome
{
    public const WON = 'won';
    public const LOST = 'lost';
    public const VOID = 'void';
    public const PENDING = 'pending';

    /**
     * @param array $outcome Row from ListingOutcomesInterface, or null when the listing has gone.
     * @return array{result: string, reason: string, price: ?float, final_price: ?float}
     */
    public static function resolve(float $maxBid, ?array $outcome, string $endAt, int $now, int $voidAfterDays): array
    {
        $result = static fn(string $result, string $reason, ?float $price = null, ?float $final = null): array =>
            ['result' => $result, 'reason' => $reason, 'price' => $price, 'final_price' => $final];
        if ($outcome === null) {
            return $result(self::VOID, 'listing_missing');
        }

        $final = $outcome['final_price'] !== null && $outcome['final_price'] > 0 ? (float)$outcome['final_price'] : null;
        $status = $outcome['status'];
        if ($status === 'sold' && $final !== null) {
            if ($final > $maxBid + 0.001) {
                return $result(self::LOST, 'outbid', null, $final);
            }
            return $result(self::WON, 'won_above_final', round(min($maxBid, BidIncrements::next($final)), 2), $final);
        }

        if ($status === 'ended') {
            if (($outcome['outcome_reason'] ?? null) === 'reserve_not_met') {
                return $result(self::LOST, 'reserve_not_met');
            }
            $bids = $outcome['bids_count'];
            if ($bids === 0 || ($bids === null && $final === null)) {
                // No one bid, so ours would have been the only bid, at the starting price.
                $start = (float)($outcome['price_current'] ?? 0);
                return $start > 0 && $start <= $maxBid + 0.001
                    ? $result(self::WON, 'won_unopposed', round($start, 2))
                    : $result(self::LOST, 'below_start');
            }
            return $result(self::VOID, 'price_unverified');
        }

        $end = strtotime($endAt . ' UTC');
        if ($end !== false && $now > $end + $voidAfterDays * 86400) {
            return $result(self::VOID, 'no_outcome');
        }
        return $result(self::PENDING, 'awaiting_outcome');
    }
}
