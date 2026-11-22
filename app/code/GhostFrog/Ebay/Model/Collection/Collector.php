<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Collection;

use DateTimeImmutable;
use DateTimeZone;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class Collector
{
    private const CONFIG = 'ghostfrog_ebay/';

    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly MysqlListings $listings,
        private readonly BrowseClient $browse,
        private readonly ListingSummary $summary,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * CLI previews return a reason per source. Successful collection returns the
     * count of listings committed per source and marks failures separately.
     */
    public function run(?string $domain = null, bool $preview = false): array
    {
        if ($domain !== null && !isset(SourceSearches::DEFINITIONS[$domain])) {
            throw new RuntimeException('Unknown eBay source domain.');
        }
        if (!$preview && !$this->isEnabled()) {
            return ['collector' => ['status' => 'disabled']];
        }
        if (!$preview && !$this->listings->tryLock()) {
            return ['collector' => ['status' => 'already_running']];
        }

        try {
            $results = [];
            $sources = $this->listings->enabledSources();
            foreach ($sources as $source) {
                $key = $source['domain'];
                if (!isset(SourceSearches::DEFINITIONS[$key]) || ($domain !== null && $key !== $domain)
                    || isset($results[$key])
                ) {
                    continue;
                }

                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $results[$key] = $this->schedule($source, $now);
                if ($preview || $results[$key]['status'] !== 'due') {
                    continue;
                }
                try {
                    $results[$key] = ['status' => 'collected', 'listings' => $this->collect($source, $now)];
                } catch (Throwable $exception) {
                    // Exceptions from HTTP clients or the database can contain credentials or listing data.
                    $message = get_class($exception) === RuntimeException::class
                        ? $exception->getMessage() : get_class($exception);
                    $this->logger->error('GhostFrog collection failed for {source}. {reason}', [
                        'source' => $key,
                        'reason' => $message,
                    ]);
                    $results[$key] = ['status' => 'failed', 'reason' => $message];
                    if ($message === 'eBay Browse returned HTTP 429.') {
                        break;
                    }
                }
            }

            if ($domain !== null && $results === []) {
                return [$domain => ['status' => 'missing_or_disabled']];
            }
            return $results;
        } finally {
            if (!$preview) {
                $this->listings->unlock();
            }
        }
    }

    private function isEnabled(): bool
    {
        return $this->config->isSetFlag(self::CONFIG . 'general/enabled')
            && $this->config->isSetFlag(self::CONFIG . 'heartbeat/gf_heartbeat_enable_scrape')
            && $this->config->isSetFlag(self::CONFIG . 'ebay_api/gf_allow_ebay_api');
    }

    private function schedule(array $source, DateTimeImmutable $now): array
    {
        $tz = $this->setting('scraping/gf_scrape_tz', 'Europe/London');
        $start = (int)$this->setting('scraping/gf_scrape_start_hour', '7');
        $end = (int)$this->setting('scraping/gf_scrape_end_hour', '23');
        if ($start < 0 || $start > 23 || $end < 0 || $end > 24) {
            throw new RuntimeException('Invalid GhostFrog scrape window.');
        }
        try {
            $local = $now->setTimezone(new DateTimeZone($tz));
        } catch (\Exception) {
            throw new RuntimeException('Invalid GhostFrog scrape time zone.');
        }
        $hour = (int)$local->format('G');
        $allowed = $start < $end ? $start <= $hour && $hour < $end : $hour >= $start || $hour < $end;
        $last = $source['last_scraped_at'] === null
            ? null : new DateTimeImmutable($source['last_scraped_at'], new DateTimeZone('UTC'));
        $interval = max(0, (int)$source['scrape_interval_seconds']);
        $next = $last?->modify("+$interval seconds");
        $status = match (true) {
            !$allowed => 'off_hours',
            $next !== null && $next > $now => 'waiting',
            default => 'due',
        };

        return [
            'status' => $status,
            'interval_seconds' => $interval,
            'last_scraped_at' => $last?->format(DATE_ATOM),
            'interval_due_at' => $next?->format(DATE_ATOM),
        ];
    }

    private function collect(array $source, DateTimeImmutable $now): int
    {
        $definition = SourceSearches::DEFINITIONS[$source['domain']];
        $sellerMode = isset($definition['seller']);
        $rows = [];
        $unavailable = [];
        foreach ($this->browse->search($definition, $now) as [$saleType, $item]) {
            $id = $this->summary->externalId($item, $sellerMode);
            if ($id === '') {
                continue;
            }
            if ($this->summary->unavailable($item, $now)) {
                $unavailable[$id] = true;
                continue;
            }
            $row = $this->summary->normalize($item, $saleType, $now);
            if ($row !== null) {
                $rows[$id] = $row;
            }
        }

        // Overlapping searches may return both an unavailable and a live observation.
        $unavailable = array_keys(array_diff_key($unavailable, $rows));
        $staleMinutes = max(1, (int)$this->setting('listings/gf_listing_stale_minutes', '180'));
        return $this->listings->save($source, $rows, $unavailable, $now, $now->modify("-$staleMinutes minutes"));
    }

    private function setting(string $path, string $default): string
    {
        $value = $this->config->getValue(self::CONFIG . $path);
        return $value === null || $value === '' ? $default : (string)$value;
    }
}
