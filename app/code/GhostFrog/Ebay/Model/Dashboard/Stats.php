<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Dashboard;

use GhostFrog\Ebay\Model\Api\UsageRows;
use GhostFrog\Ebay\Model\ResourceModel\Listing;
use GhostFrog\Ebay\Model\ResourceModel\Source;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Throwable;

/**
 * Aggregates for the admin dashboard. Several queries scan the whole listings table, so the
 * result is cached for a few minutes and rebuilt on demand from the dashboard's Refresh button.
 * Listing figures cover active sources only, matching the work the pipeline actually does; the
 * comps index keeps sales from paused sources, so its summary is unfiltered.
 */
final class Stats
{
    private const CACHE_KEY = 'ghostfrog_ebay_dashboard_stats';
    private const CACHE_LIFETIME = 300;

    /** Magento queue_message_status codes. */
    private const QUEUE_STATUSES = [2 => 'pending', 3 => 'in_progress', 4 => 'complete', 5 => 'retry', 6 => 'failed'];

    private const QUEUES = [
        'ghostfrog.ebay.enrichment' => 'Trading enrichment',
        'ghostfrog.ebay.condition_text' => 'Text condition (LLM)',
        'ghostfrog.ebay.condition_visual' => 'Image condition (LLM)',
        'ghostfrog.ebay.model_key' => 'Model key',
        'ghostfrog.ebay.condition_text_backfill' => 'Text condition backfill',
        'ghostfrog.ebay.condition_visual_backfill' => 'Image condition backfill',
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CacheInterface $cache,
        private readonly UsageRows $usage
    ) {
    }

    public function get(bool $refresh = false): array
    {
        if (!$refresh) {
            $cached = $this->cache->load(self::CACHE_KEY);
            $data = $cached ? json_decode($cached, true) : null;
            if (is_array($data)) {
                return $data;
            }
        }
        $data = $this->build();
        $this->cache->save(json_encode($data), self::CACHE_KEY, [], self::CACHE_LIFETIME);
        return $data;
    }

    private function build(): array
    {
        $started = microtime(true);
        $data = [
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'listings' => $this->listings(),
            'comps' => $this->comps(),
            'queues' => $this->queues(),
            'opportunities' => $this->opportunities(),
            'api' => $this->api(),
        ];
        $data['build_seconds'] = round(microtime(true) - $started, 2);
        return $data;
    }

    private function listings(): array
    {
        $db = $this->resource->getConnection();
        $table = $this->table(Listing::TABLE);
        $unknownKey = "(a.model_key LIKE 'unknown%' OR a.model_key LIKE '%generic%' OR a.model_key LIKE '%gen-unk%')";
        $priced = '(a.final_price > 0)';
        $ended = 'a.end_time < UTC_TIMESTAMP()';
        $active = Source::activeListing($this->resource, 'a');

        $totals = $db->fetchRow($db->select()->from(['a' => $table], [
            'total' => new Expression('COUNT(*)'),
            'new_24h' => new Expression('SUM(a.first_seen >= UTC_TIMESTAMP() - INTERVAL 1 DAY)'),
            'new_7d' => new Expression('SUM(a.first_seen >= UTC_TIMESTAMP() - INTERVAL 7 DAY)'),
            'ending_24h' => new Expression(
                'SUM(a.end_time BETWEEN UTC_TIMESTAMP() AND UTC_TIMESTAMP() + INTERVAL 1 DAY)'
            ),
            'ended' => new Expression("SUM($ended)"),
            'ended_priced' => new Expression("SUM($ended AND $priced)"),
            'ended_recoverable' => new Expression(
                "SUM($ended AND NOT COALESCE($priced, 0) AND a.end_time >= UTC_TIMESTAMP() - INTERVAL 90 DAY)"
            ),
            'ended_lost' => new Expression(
                "SUM($ended AND NOT COALESCE($priced, 0) AND a.end_time < UTC_TIMESTAMP() - INTERVAL 90 DAY)"
            ),
            'no_end_time' => new Expression('SUM(a.end_time IS NULL)'),
            'no_end_time_priced' => new Expression("SUM(a.end_time IS NULL AND $priced)"),
            'priced' => new Expression("SUM($priced)"),
            'mk_missing' => new Expression("SUM(a.model_key IS NULL OR a.model_key = '')"),
            'mk_unknown' => new Expression("SUM($unknownKey)"),
            'enriched' => new Expression(
                "SUM(JSON_EXTRACT(a.raw_attrs, '$._trading_enrichment_status') IS NOT NULL)"
            ),
            'text_checked' => new Expression('SUM(a.text_condition_checked = 1)'),
            'visual_checked' => new Expression('SUM(a.visual_condition_checked = 1)'),
        ])->where($active));
        $totals = array_map('intval', $totals);
        $totals['mk_valid'] = $totals['total'] - $totals['mk_missing'] - $totals['mk_unknown'];
        $paused = $db->fetchRow($db->select()
            ->from(['a' => $table], ['listings' => new Expression('COUNT(*)')])
            ->join(['p' => $this->table(Source::TABLE)], 'p.id = a.source_id', [
                'sources' => new Expression('COUNT(DISTINCT p.id)'),
            ])
            ->where('COALESCE(p.enabled, 0) <> 1'));
        $totals['paused_listings'] = (int)$paused['listings'];
        $totals['paused_sources'] = (int)$paused['sources'];

        return [
            'totals' => $totals,
            'status' => $this->pairs($db->select()->from(['a' => $table], [
                'label' => new Expression("COALESCE(a.status, '(none)')"), 'n' => new Expression('COUNT(*)'),
            ])->where($active)->group('a.status')->order('n DESC')),
            'sale_type' => $this->pairs($db->select()->from(['a' => $table], [
                'label' => new Expression("COALESCE(a.sale_type, '(none)')"), 'n' => new Expression('COUNT(*)'),
            ])->where($active)->group('a.sale_type')->order('n DESC')),
            'grade' => $this->pairs($db->select()->from(['a' => $table], [
                'label' => new Expression(
                    "CASE WHEN a.condition_grade IN ('A','B','C','D') THEN a.condition_grade ELSE 'Ungraded' END"
                ),
                'n' => new Expression('COUNT(*)'),
            ])->where($active)->group('label')->order('label')),
            'daily_new' => $this->daily($table),
            'by_source' => $db->fetchAll($db->select()
                ->from(['a' => $table], [
                    'source' => new Expression('COALESCE(s.name, a.source)'),
                    'total' => new Expression('COUNT(*)'),
                    'live' => new Expression("SUM(a.status = 'live')"),
                    'priced' => new Expression("SUM($priced)"),
                ])
                ->joinLeft(['s' => $this->table(Source::TABLE)], 's.id = a.source_id', [])
                ->where($active)
                ->group('source')->order('total DESC')),
        ];
    }

    /** New listings per UTC day for the last 14 days, zero-filled. */
    private function daily(string $table): array
    {
        $db = $this->resource->getConnection();
        $rows = $db->fetchPairs($db->select()->from(['a' => $table], [
            'd' => new Expression('DATE(a.first_seen)'), 'n' => new Expression('COUNT(*)'),
        ])->where('a.first_seen >= UTC_DATE() - INTERVAL 13 DAY')
            ->where(Source::activeListing($this->resource, 'a'))->group('d'));
        $days = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', strtotime("-$i days"));
            $days[$day] = (int)($rows[$day] ?? 0);
        }
        return $days;
    }

    private function comps(): array
    {
        $db = $this->resource->getConnection();
        $comps = $this->table('ghostfrog_ebay_comps');
        $listings = $this->table(Listing::TABLE);

        $summary = array_map(fn ($v) => $v === null ? null : (is_numeric($v) ? (int)$v : $v), $db->fetchRow(
            $db->select()->from($comps, [
                'groups' => new Expression('COUNT(*)'),
                'model_keys' => new Expression('COUNT(DISTINCT model_key)'),
                'samples' => new Expression('SUM(samples)'),
                'last_computed' => new Expression('MAX(computed_at)'),
                'stale_7d' => new Expression('SUM(computed_at < UTC_TIMESTAMP() - INTERVAL 7 DAY)'),
            ])
        ));
        $summary['sales'] = (int)$db->fetchOne($db->select()->from(
            $this->table('ghostfrog_ebay_comp_sales'),
            new Expression('COUNT(*)')
        ));

        $buckets = $db->fetchPairs($db->select()->from($comps, [
            'b' => new Expression(
                "CASE WHEN samples <= 1 THEN '1 sale' WHEN samples <= 4 THEN '2–4 sales'"
                . " WHEN samples <= 9 THEN '5–9 sales' ELSE '10+ sales' END"
            ),
            'n' => new Expression('COUNT(*)'),
        ])->group('b'));
        $sampleBuckets = [];
        foreach (['1 sale', '2–4 sales', '5–9 sales', '10+ sales'] as $label) {
            $sampleBuckets[$label] = (int)($buckets[$label] ?? 0);
        }

        $coverage = array_map('intval', $db->fetchRow($db->select()
            ->from(['a' => $listings], [
                'live' => new Expression('COUNT(*)'),
                'with_comp' => new Expression('SUM(c.model_key IS NOT NULL)'),
                'with_good_comp' => new Expression('SUM(c.samples >= 5)'),
            ])
            ->joinLeft(
                ['c' => $comps],
                'c.model_key = a.model_key AND c.condition_grade = a.condition_grade',
                []
            )
            ->where("a.status = 'live'")
            ->where(Source::activeListing($this->resource, 'a'))));

        return [
            'summary' => $summary,
            'sample_buckets' => $sampleBuckets,
            'grades' => $this->pairs($db->select()->from($comps, [
                'label' => new Expression("COALESCE(condition_grade, '?')"), 'n' => new Expression('COUNT(*)'),
            ])->group('condition_grade')->order('label')),
            'live_coverage' => $coverage,
            'top_models' => $db->fetchAll($db->select()->from($comps, [
                'model_key', 'condition_grade', 'samples', 'median_final_price',
            ])->order('samples DESC')->limit(10)),
            'index_backlog' => [
                'comps' => $this->mviewBacklog('ghostfrog_ebay_comps'),
                'opportunities' => $this->mviewBacklog('ghostfrog_ebay_opportunities'),
            ],
        ];
    }

    private function queues(): array
    {
        $db = $this->resource->getConnection();
        $rows = $db->fetchAll($db->select()
            ->from(['s' => $this->table('queue_message_status')], ['status', 'n' => new Expression('COUNT(*)')])
            ->join(['q' => $this->table('queue')], 'q.id = s.queue_id', ['name'])
            ->where('q.name IN (?)', array_keys(self::QUEUES))
            ->group(['q.name', 's.status']));

        $message = [];
        foreach (self::QUEUES as $name => $label) {
            $message[$name] = ['label' => $label] + array_fill_keys(self::QUEUE_STATUSES, 0);
        }
        foreach ($rows as $row) {
            $status = self::QUEUE_STATUSES[(int)$row['status']] ?? null;
            if ($status !== null) {
                $message[$row['name']][$status] = (int)$row['n'];
            }
        }

        return [
            'message' => array_values($message),
            'final_price' => array_map('intval', $db->fetchRow($db->select()
                ->from($this->table('ghostfrog_ebay_final_price_queue'), [
                    'queued' => new Expression('COUNT(*)'),
                    'ready' => new Expression('COALESCE(SUM(available_at <= UTC_TIMESTAMP()), 0)'),
                    'needs_comp' => new Expression('COALESCE(SUM(needs_comp = 1), 0)'),
                ]))),
            'price_refresh' => array_map('intval', $db->fetchRow($db->select()
                ->from($this->table('ghostfrog_ebay_price_refresh_queue'), [
                    'queued' => new Expression('COUNT(*)'),
                    'due' => new Expression('COALESCE(SUM(due_at <= UTC_TIMESTAMP()), 0)'),
                ]))),
            'notifications' => $this->pairs($db->select()
                ->from($this->table('ghostfrog_ebay_opportunity_notification_queue'), [
                    'label' => 'status', 'n' => new Expression('COUNT(*)'),
                ])->group('status')),
        ];
    }

    private function opportunities(): array
    {
        $db = $this->resource->getConnection();
        $table = $this->table('ghostfrog_ebay_opportunities');
        $summary = $db->fetchRow($db->select()->from($table, [
            'count' => new Expression('COUNT(*)'),
            'profit' => new Expression('COALESCE(SUM(expected_profit), 0)'),
            'median_roi' => new Expression('NULL'),
            'ending_24h' => new Expression(
                'COALESCE(SUM(end_time BETWEEN UTC_TIMESTAMP() AND UTC_TIMESTAMP() + INTERVAL 1 DAY), 0)'
            ),
        ]));
        $rois = array_map('floatval', $db->fetchCol($db->select()->from($table, 'expected_roi_percent')
            ->where('expected_roi_percent IS NOT NULL')->order('expected_roi_percent')));
        // Median, not mean: a near-zero buy price produces six-figure ROI percentages.
        $summary['median_roi'] = $rois ? $rois[intdiv(count($rois), 2)] : null;

        $roiBuckets = ['< 20%' => 0, '20–50%' => 0, '50–100%' => 0, '100%+' => 0];
        foreach ($rois as $roi) {
            $key = $roi < 20 ? '< 20%' : ($roi < 50 ? '20–50%' : ($roi < 100 ? '50–100%' : '100%+'));
            $roiBuckets[$key]++;
        }

        return [
            'summary' => $summary,
            'by_source' => $this->pairs($db->select()->from($table, [
                'label' => new Expression("COALESCE(source, '(none)')"), 'n' => new Expression('COUNT(*)'),
            ])->group('source')->order('n DESC')),
            'by_sale_type' => $this->pairs($db->select()->from($table, [
                'label' => new Expression("COALESCE(sale_type, '(none)')"), 'n' => new Expression('COUNT(*)'),
            ])->group('sale_type')->order('n DESC')),
            'roi_buckets' => $roiBuckets,
            'top' => $db->fetchAll($db->select()->from($table, [
                'listing_id', 'title', 'source', 'sale_type', 'current_price', 'sold_median',
                'expected_profit', 'expected_roi_percent', 'end_time', 'url',
            ])->order('expected_profit DESC')->limit(8)),
        ];
    }

    private function api(): array
    {
        try {
            return $this->usage->rows();
        } catch (Throwable) {
            return [];
        }
    }

    /** Changelog rows not yet applied by the indexer's mview subscription. */
    private function mviewBacklog(string $view): ?int
    {
        $db = $this->resource->getConnection();
        $changelog = $this->table($view . '_cl');
        if (!$db->isTableExists($changelog)) {
            return null;
        }
        $applied = (int)$db->fetchOne($db->select()->from($this->table('mview_state'), 'version_id')
            ->where('view_id = ?', $view));
        return (int)$db->fetchOne($db->select()->from($changelog, new Expression('COUNT(*)'))
            ->where('version_id > ?', $applied));
    }

    private function pairs(\Magento\Framework\DB\Select $select): array
    {
        return array_map('intval', $this->resource->getConnection()->fetchPairs($select));
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }
}
