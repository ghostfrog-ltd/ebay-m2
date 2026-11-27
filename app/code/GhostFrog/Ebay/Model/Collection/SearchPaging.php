<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Collection;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Decides when a category search has read far enough. A single page per run either
 * repeats the previous run's listings or misses everything past the first 50, so each
 * search pages until it reaches listings the previous run covered.
 *
 * Buy It Now searches sort by newest, so they stop once a page reaches listings created
 * before the previous run. Auction searches sort by ending soonest, so they stop once a
 * page reaches auctions that end after the next run, which will see them again.
 */
final class SearchPaging
{
    public const DEFAULT_MAX_PAGES = 4;
    // Margin for runs that start late, so neither boundary leaves a gap.
    public const OVERLAP_SECONDS = 900;

    public const CAUGHT_UP = 'caught_up';
    public const EXHAUSTED = 'exhausted';
    public const CAPPED = 'capped';
    public const QUOTA = 'quota';
    public const FIRST_RUN = 'first_run';
    public const NO_DATES = 'no_dates';

    /** The point each sale type must reach: the previous run for BIN, the next run for auctions. */
    public static function boundary(
        string $saleType,
        DateTimeImmutable $now,
        ?DateTimeImmutable $lastRun,
        int $intervalSeconds
    ): ?DateTimeImmutable {
        if ($saleType === 'bin') {
            return $lastRun?->modify('-' . self::OVERLAP_SECONDS . ' seconds');
        }
        return $now->modify('+' . (max(0, $intervalSeconds) + self::OVERLAP_SECONDS) . ' seconds');
    }

    /** The reason to stop after a fetched page, or null to fetch the next one. */
    public static function stopReason(
        string $saleType,
        array $items,
        ?DateTimeImmutable $boundary,
        bool $hasNext,
        int $pagesFetched,
        int $maxPages
    ): ?string {
        if ($items === []) {
            return self::EXHAUSTED;
        }
        if ($boundary === null) {
            return self::FIRST_RUN;
        }

        $dates = self::dates($items, $saleType === 'bin' ? 'itemCreationDate' : 'itemEndDate');
        if ($dates === null) {
            return self::NO_DATES;
        }
        $reached = $saleType === 'bin' ? min($dates) <= $boundary : max($dates) >= $boundary;
        if ($reached) {
            return self::CAUGHT_UP;
        }
        if (!$hasNext) {
            return self::EXHAUSTED;
        }
        return $pagesFetched >= $maxPages ? self::CAPPED : null;
    }

    /** Listings on a page that the previous run should already have seen. */
    public static function repeats(string $saleType, array $items, ?DateTimeImmutable $lastRun): int
    {
        if ($saleType !== 'bin' || $lastRun === null) {
            return 0;
        }
        $dates = self::dates($items, 'itemCreationDate') ?? [];
        return count(array_filter($dates, static fn (DateTimeImmutable $date): bool => $date <= $lastRun));
    }

    /** @return DateTimeImmutable[]|null Null when any listing lacks a readable date. */
    private static function dates(array $items, string $field): ?array
    {
        $dates = [];
        foreach ($items as $item) {
            $value = $item[$field] ?? null;
            if (!is_string($value) || $value === '') {
                return null;
            }
            try {
                $dates[] = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            } catch (Exception) {
                return null;
            }
        }
        return $dates;
    }
}
