<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ResourceModel;

use GhostFrog\Ebay\Model\Collection\SourceSearches;
use GhostFrog\Ebay\Model\Opportunities\Index;
use GhostFrog\Ebay\Model\Opportunities\Rows;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Indexer\IndexerRegistry;
use Throwable;

/**
 * Admin access to collection sources. Only the enabled flag and scrape interval are editable:
 * the name is copied onto collected listings and the domain selects the search definition.
 *
 * A disabled source pauses all of its listing work, not just collection. Workers apply
 * activeListing() or claim around it, so queued jobs wait and resume when it is re-enabled.
 */
class Source
{
    public const TABLE = 'ghostfrog_ebay_sources';
    public const MIN_INTERVAL_MINUTES = 1;
    public const MAX_INTERVAL_MINUTES = 10080;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly IndexerRegistry $indexers
    ) {
    }

    /**
     * SQL condition that is true when the listing aliased $listingAlias belongs to an enabled source.
     * Listings without a source row stay active because no admin switch could have paused them.
     */
    public static function activeListing(ResourceConnection $resource, string $listingAlias = 'a'): string
    {
        return sprintf(
            'NOT EXISTS (SELECT 1 FROM %s AS gf_paused_source WHERE gf_paused_source.id = %s.source_id'
            . ' AND COALESCE(gf_paused_source.enabled, 0) <> 1)',
            $resource->getConnection()->quoteIdentifier($resource->getTableName(self::TABLE)),
            $listingAlias
        );
    }

    public function get(int $id): array
    {
        return $this->load($id, false);
    }

    public function revision(array $source): string
    {
        return hash('sha256', json_encode($this->editValues($source), JSON_THROW_ON_ERROR));
    }

    /** Summarises the code-defined search for a source domain, or null when collection would skip it. */
    public static function search(?string $domain): ?array
    {
        $definition = SourceSearches::DEFINITIONS[(string)$domain] ?? null;
        if ($definition === null) {
            return null;
        }
        $queries = $definition['queries'] ?? [];
        foreach ($definition['category_queries'] ?? [] as $categoryQueries) {
            $queries = [...$queries, ...$categoryQueries];
        }
        return [
            'seller' => $definition['seller'] ?? null,
            'categories' => count($definition['categories'] ?? []),
            'queries' => count(array_unique($queries)),
        ];
    }

    /** Converts a submitted interval in whole minutes to seconds. */
    public static function intervalSeconds(mixed $minutes): int
    {
        $value = is_string($minutes) ? trim($minutes) : $minutes;
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => self::MIN_INTERVAL_MINUTES,
            'max_range' => self::MAX_INTERVAL_MINUTES,
        ]]);
        if ($value === false) {
            throw new LocalizedException(__(
                'Enter a scrape interval between %1 and %2 minutes.',
                self::MIN_INTERVAL_MINUTES,
                self::MAX_INTERVAL_MINUTES
            ));
        }
        return $value * 60;
    }

    public function save(int $id, array $input, string $revision): void
    {
        if (!in_array($input['enabled'] ?? null, ['0', '1'], true) || !array_key_exists('interval_minutes', $input)) {
            throw new LocalizedException(__('The source form is incomplete. Please try again.'));
        }
        $values = [
            'enabled' => (int)$input['enabled'],
            'scrape_interval_seconds' => self::intervalSeconds($input['interval_minutes']),
        ];

        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $source = $this->load($id, true);
            $changes = array_diff_assoc($values, $this->editValues($source));
            if ($changes !== []) {
                if (!hash_equals($this->revision($source), $revision)) {
                    throw new LocalizedException(
                        __('The source changed while you were editing it. Review the current values and try again.')
                    );
                }
                $db->update(
                    $this->resource->getTableName(self::TABLE),
                    $changes + ['updated_at_utc' => gmdate('Y-m-d H:i:s')],
                    ['id = ?' => $id]
                );
                if (($changes['enabled'] ?? null) === 0) {
                    $this->removeOpportunities([$id]);
                }
            }
            $db->commit();
        } catch (Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
        if (($changes['enabled'] ?? null) === 1) {
            $this->rebuildOpportunities();
        }
    }

    /** Returns the number of sources whose flag actually changed. */
    public function setEnabled(array $ids, bool $enabled): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return 0;
        }
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $changed = $db->update(
                $this->resource->getTableName(self::TABLE),
                ['enabled' => (int)$enabled, 'updated_at_utc' => gmdate('Y-m-d H:i:s')],
                ['id IN (?)' => $ids, 'COALESCE(enabled, 0) <> ?' => (int)$enabled]
            );
            if (!$enabled) {
                // Also clears rows left by a disable that happened before this cleanup existed.
                $this->removeOpportunities($ids);
            }
            $db->commit();
        } catch (Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
        if ($enabled && $changed > 0) {
            $this->rebuildOpportunities();
        }
        return $changed;
    }

    /** Drops opportunities for paused sources so their price checks and alerts stop with them. */
    private function removeOpportunities(array $ids): void
    {
        $db = $this->resource->getConnection();
        $listings = $db->select()
            ->from($this->resource->getTableName(Listing::TABLE), 'id')
            ->where('source_id IN (?)', $ids);
        $db->delete($this->resource->getTableName(Rows::TABLE), ['listing_id IN (?)' => $listings]);
    }

    /** Magento's indexer cron rebuilds invalid indexers within a minute, valuing the re-enabled listings. */
    private function rebuildOpportunities(): void
    {
        $this->indexers->get(Index::ID)->invalidate();
    }

    private function load(int $id, bool $lock): array
    {
        $db = $this->resource->getConnection();
        $source = $db->fetchRow($db->select()
            ->from($this->resource->getTableName(self::TABLE), [
                'id', 'name', 'domain', 'type', 'niche', 'base_url', 'enabled', 'scrape_interval_seconds',
                'last_scraped_at', 'created_at_utc', 'updated_at_utc',
            ])
            ->where('id = ?', $id)
            ->forUpdate($lock));
        if (!$source) {
            throw new NoSuchEntityException(__('This source no longer exists.'));
        }
        return $source;
    }

    private function editValues(array $source): array
    {
        return [
            'enabled' => (int)$source['enabled'] === 1 ? 1 : 0,
            'scrape_interval_seconds' => (int)$source['scrape_interval_seconds'],
        ];
    }
}
