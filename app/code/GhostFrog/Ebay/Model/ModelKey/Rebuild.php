<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey;

use Magento\Framework\App\ResourceConnection;

/**
 * Re-runs the classifier over stored listings, outside the enrichment and condition queues.
 *
 * Keys depend only on the title and item specifics, so listings waiting on condition checks, listings
 * collected before a source had rules, and listings stuck on "unknown" after a rule change can all be
 * keyed straight away. By default only missing and unknown keys are written; existing keys are kept
 * unless $all is set, because older keys may come from looser rules that comps already rely on.
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
     * @return array<string, array{checked: int, keyed: int, changed: int, unknown: int}>
     */
    public function run(?string $source = null, bool $all = false, bool $dryRun = false, ?callable $progress = null): array
    {
        $db = $this->resource->getConnection();
        $listings = $this->resource->getTableName('ghostfrog_ebay_auction_listings');
        $sources = $db->select()->from($this->resource->getTableName('ghostfrog_ebay_sources'), ['id', 'domain']);
        if ($source !== null) {
            $sources->where('domain = ? OR name = ?', $source);
        }
        $report = [];
        foreach ($db->fetchPairs($sources) as $sourceId => $domain) {
            $counts = ['checked' => 0, 'keyed' => 0, 'changed' => 0, 'unknown' => 0];
            $lastId = 0;
            do {
                $select = $db->select()
                    ->from($listings, array_merge(['id', 'title', 'raw_attrs', 'model_key'], self::FIELDS))
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
                    $key = $this->classifier->key((string)$domain, (string)$row['title'],
                        (is_array($attrs) ? $attrs : []) + array_intersect_key($row, array_flip(self::FIELDS)));
                    $old = (string)$row['model_key'];
                    if (str_starts_with($key, 'unknown')) {
                        $counts['unknown']++;
                    } elseif ($old === '' || str_starts_with($old, 'unknown')) {
                        $counts['keyed']++;
                    } elseif ($old !== $key) {
                        $counts['changed']++;
                    }
                    // A blank key means "not classified yet", so an unknown result is still written.
                    if ($key !== $old) {
                        $updates[$key][] = $lastId;
                    }
                }
                if (!$dryRun) {
                    foreach ($updates as $key => $ids) {
                        $db->update($listings, ['model_key' => $key], ['id IN (?)' => $ids]);
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
