<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ResourceModel;

use GhostFrog\Ebay\Model\Collection\SourceSearches;
use GhostFrog\Ebay\Model\Comps\Pricing;
use GhostFrog\Ebay\Model\Comps\Valuation;
use GhostFrog\Ebay\Model\Opportunities\Index;
use GhostFrog\Ebay\Model\Opportunities\Rows;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Indexer\IndexerRegistry;
use Throwable;

/**
 * Admin access to collection sources. The enabled flag, scrape interval, profit and sold-comp minimums,
 * shipping cost and display colour are editable: the name is copied onto collected listings and the domain selects the search
 * definition. A blank minimum or shipping cost falls back to the global Comps and Pricing setting.
 *
 * A disabled source pauses all of its listing work, not just collection. Workers apply
 * activeListing() or claim around it, so queued jobs wait and resume when it is re-enabled.
 */
class Source
{
    public const TABLE = 'ghostfrog_ebay_sources';
    public const MIN_INTERVAL_MINUTES = 1;
    public const MAX_INTERVAL_MINUTES = 10080;
    private const PRICING_FIELDS = ['min_profit', 'min_roi_percent', 'shipping', 'selling_fee_percent', 'min_comps'];
    public const MAX_MIN_COMPS = 1000;

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

    /** Scalar subquery for the display colour of a listing's source, matched on source_id. */
    public static function colourOf(ResourceConnection $resource, string $listingAlias = 'a'): Expression
    {
        return new Expression(sprintf(
            '(SELECT gf_colour_source.colour FROM %s AS gf_colour_source WHERE gf_colour_source.id = %s.source_id)',
            $resource->getConnection()->quoteIdentifier($resource->getTableName(self::TABLE)),
            $listingAlias
        ));
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

    /** Normalises a blank-or-amount minimum, shipping cost or fee to a two-decimal string, or null for the global setting. */
    public static function minimum(mixed $value, string $field): ?string
    {
        $value = is_string($value) ? trim($value) : $value;
        if ($value === null || $value === '') {
            return null;
        }
        $setting = match ($field) {
            'min_roi_percent' => Pricing::TARGET_ROI,
            'shipping' => Pricing::SHIPPING,
            'selling_fee_percent' => Pricing::SELLING_FEE,
            default => 'target_profit',
        };
        if (!Pricing::validSetting($value, $setting)) {
            throw new LocalizedException(match ($field) {
                'min_roi_percent' => __('Enter a minimum ROI from 0 to %1%, or leave it blank.', (int)Pricing::MAX_ROI_PERCENT),
                'shipping' => __('Enter a non-negative shipping cost in GBP, or leave it blank.'),
                'selling_fee_percent' => __('Enter a selling fee from 0 to 100%, or leave it blank.'),
                default => __('Enter a non-negative minimum profit in GBP, or leave it blank.'),
            });
        }
        return number_format((float)$value, 2, '.', '');
    }

    /** Parses the sold-comp minimum a model and grade needs before its listings are valued. */
    public static function minComps(mixed $value): int
    {
        $value = is_string($value) ? trim($value) : $value;
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 1,
            'max_range' => self::MAX_MIN_COMPS,
        ]]);
        if ($value === false) {
            throw new LocalizedException(__('Enter a minimum of sold comps between 1 and %1.', self::MAX_MIN_COMPS));
        }
        return $value;
    }

    /** Normalises a #rrggbb colour to lower case, or null when blank. */
    public static function colour(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : $value;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match('/\A#[0-9a-f]{6}\z/', $value)) {
            throw new LocalizedException(__('Enter the colour as a hex value such as #1a73e8.'));
        }
        return $value;
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
        foreach (self::PRICING_FIELDS as $field) {
            if (array_key_exists($field, $input)) {
                $values[$field] = $field === 'min_comps'
                    ? self::minComps($input[$field])
                    : self::minimum($input[$field], $field);
            }
        }
        if (array_key_exists('colour', $input)) {
            $values['colour'] = self::colour($input['colour']);
        }

        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $source = $this->load($id, true);
            $current = $this->editValues($source);
            $changes = array_filter(
                $values,
                static fn($value, string $field): bool => $value !== $current[$field],
                ARRAY_FILTER_USE_BOTH
            );
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
        $repriced = array_intersect_key($changes, array_flip(self::PRICING_FIELDS)) !== [];
        if (($changes['enabled'] ?? null) === 1 || ($repriced && ($changes['enabled'] ?? $current['enabled']) === 1)) {
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
                'min_profit', 'min_roi_percent', 'shipping', 'selling_fee_percent', 'min_comps', 'colour', 'last_scraped_at', 'created_at_utc', 'updated_at_utc',
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
        $values = [
            'enabled' => (int)$source['enabled'] === 1 ? 1 : 0,
            'scrape_interval_seconds' => (int)$source['scrape_interval_seconds'],
        ];
        foreach (['min_profit', 'min_roi_percent', 'shipping', 'selling_fee_percent'] as $field) {
            $value = $source[$field] ?? null;
            $values[$field] = $value === null ? null : number_format((float)$value, 2, '.', '');
        }
        $values['min_comps'] = (int)($source['min_comps'] ?? Valuation::DEFAULT_MINIMUM);
        $values['colour'] = isset($source['colour']) && $source['colour'] !== '' ? strtolower($source['colour']) : null;
        return $values;
    }
}
