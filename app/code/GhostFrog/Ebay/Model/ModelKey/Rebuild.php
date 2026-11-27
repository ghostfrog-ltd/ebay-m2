<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey;

use GhostFrog\Ebay\Model\Enrichment\Queue;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

/**
 * Re-runs the classifier over stored listings, outside the enrichment and condition queues.
 *
 * Keys depend only on the title and item specifics, so listings waiting on condition checks, listings
 * collected before a source had rules, and listings stuck on "unknown" after a rule change can all be
 * keyed straight away. By default only missing and unknown keys are written; existing keys are kept
 * unless $all is set, because older keys may come from looser rules that comps already rely on.
 *
 * Every status is covered, so sold and ended listings are re-keyed together with the live ones they are
 * comparables for. Only stored data is read; nothing is fetched from eBay. A row is written only when a
 * column would change, which makes a second run a no-op.
 *
 * A live listing whose enrichment and condition checks have not finished gets its new key marked as pending,
 * as at collection, so it cannot alert until the model-key worker confirms it at the end of the pipeline.
 */
final class Rebuild
{
    private const BATCH_SIZE = 500;
    private const FIELDS = ['brand', 'model_name', 'storage_gb', 'colour', 'epid'];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Classifier $classifier
    ) {
    }

    /**
     * @param callable(string, array): void|null $progress called per source with its running counts
     * @param callable(array, array): void|null $observe called per listing with its stored row and the
     *        columns the classifier gives it now
     * @return array<string, array{checked: int, keyed: int, changed: int, unknown: int, written: int}>
     */
    public function run(
        ?string $source = null,
        bool $all = false,
        bool $dryRun = false,
        ?callable $progress = null,
        ?callable $observe = null
    ): array {
        $db = $this->resource->getConnection();
        $listings = $this->resource->getTableName('ghostfrog_ebay_auction_listings');
        $sources = $db->select()->from($this->resource->getTableName('ghostfrog_ebay_sources'), ['id', 'domain']);
        if ($source !== null) {
            $sources->where('domain = ? OR name = ?', $source);
        }
        $report = [];
        foreach ($db->fetchPairs($sources) as $sourceId => $domain) {
            $counts = ['checked' => 0, 'keyed' => 0, 'changed' => 0, 'unknown' => 0, 'written' => 0];
            $lastId = 0;
            do {
                $select = $db->select()
                    ->from($listings, array_merge(
                        ['id', 'status', 'title', 'raw_attrs', 'model_key'],
                        self::FIELDS,
                        array_diff(Classifier::DETAIL_COLUMNS, self::FIELDS)
                    ))
                    ->where('source_id = ?', (int)$sourceId)
                    ->where('id > ?', $lastId)
                    ->order('id')
                    ->limit(self::BATCH_SIZE);
                if (!$all) {
                    $select->where("model_key IS NULL OR model_key = '' OR model_key LIKE 'unknown%'");
                }
                $rows = $db->fetchAll($select);
                $updates = [];
                foreach ($rows as $row) {
                    $lastId = (int)$row['id'];
                    $counts['checked']++;
                    $attrs = json_decode((string)$row['raw_attrs'], true);
                    $found = $this->classifier->columns((string)$domain, (string)$row['title'],
                        (is_array($attrs) ? $attrs : []) + array_intersect_key($row, array_flip(self::FIELDS)));
                    if ($observe !== null) {
                        $observe($row, $found);
                    }
                    $key = $found['model_key'];
                    $old = (string)$row['model_key'];
                    if (str_starts_with($key, 'unknown')) {
                        $counts['unknown']++;
                    } elseif ($old === '' || str_starts_with($old, 'unknown')) {
                        $counts['keyed']++;
                    } elseif ($old !== $key) {
                        $counts['changed']++;
                    }
                    // A blank key means "not classified yet", so an unknown result is still written.
                    $changes = [];
                    foreach ($found as $column => $value) {
                        if ($value !== $row[$column]) {
                            $changes[$column] = $value;
                        }
                    }
                    if ($changes === []) {
                        continue;
                    }
                    $counts['written']++;
                    $unchecked = $row['status'] === 'live' && !str_starts_with($key, 'unknown')
                        && ($old === '' || str_starts_with($old, 'unknown'))
                        && empty($attrs[Queue::COMPLETED_KEY]);
                    $group = json_encode([$changes, $unchecked], JSON_THROW_ON_ERROR);
                    $updates[$group] ??= [$changes, $unchecked, []];
                    $updates[$group][2][] = $lastId;
                }
                if (!$dryRun) {
                    foreach ($updates as [$changes, $unchecked, $ids]) {
                        if ($unchecked) {
                            $changes['raw_attrs'] = new Expression(
                                "JSON_SET(COALESCE(raw_attrs, JSON_OBJECT()), '" . Pending::PATH . "', true)"
                            );
                        }
                        $db->update($listings, $changes, ['id IN (?)' => $ids]);
                    }
                }
            } while (count($rows) === self::BATCH_SIZE);
            $report[(string)$domain] = $counts;
            if ($progress !== null) {
                $progress((string)$domain, $counts);
            }
        }
        return $report;
    }
}
