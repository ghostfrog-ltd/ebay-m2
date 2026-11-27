<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Comps;

use DateTimeImmutable;
use DateTimeZone;
use GhostFrog\Ebay\Model\Indexing\Transaction;
use GhostFrog\Ebay\Model\Opportunities\Rows;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;

class Index implements ActionInterface, MviewActionInterface
{
    public const ID = 'ghostfrog_ebay_comps';
    public const TABLE = 'ghostfrog_ebay_comps';
    private const SALES_TABLE = 'ghostfrog_ebay_comp_sales';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Transaction $transaction,
        private readonly Listings $listings,
        private readonly Eligibility $eligibility,
        private readonly Rows $opportunities
    ) {
    }

    public function executeFull(): void
    {
        $this->transaction->run(function (): void {
            [$from, $until] = $this->window();
            $sales = $this->winners($this->listings->sold($from, $until));
            $rows = $this->statistics(array_column($sales, 'row'), $until);
            $db = $this->resource->getConnection();
            $table = $this->resource->getTableName(self::TABLE);
            $salesTable = $this->resource->getTableName(self::SALES_TABLE);
            $affected = $this->changedValuations($db->fetchAll($db->select()->from($table)), $rows);

            $db->delete($salesTable);
            $this->insert($salesTable, array_column($sales, 'row'));
            $db->delete($table);
            $this->insert($table, $rows);
            $this->opportunities->refreshCohorts($affected);
        });
    }

    public function refreshSales(array $ids): void
    {
        $this->executeList($ids);
    }

    public function executeList(array $ids): void
    {
        $ids = array_unique(array_filter(array_map('intval', $ids), static fn (int $id) => $id > 0));
        if ($ids === []) {
            return;
        }

        $this->transaction->run(function () use ($ids): void {
            [$from, $until] = $this->window();
            $affected = [];
            foreach (array_chunk($ids, 500) as $batch) {
                $this->refreshWinners($batch, $from, $until, $affected);
            }
            if ($affected === []) {
                return;
            }

            $db = $this->resource->getConnection();
            $table = $this->resource->getTableName(self::TABLE);
            $salesTable = $this->resource->getTableName(self::SALES_TABLE);
            foreach ($affected as $key => $grades) {
                $where = ['model_key = ?' => $key, 'condition_grade IN (?)' => array_keys($grades)];
                $sales = $db->fetchAll($db->select()->from($salesTable)
                    ->where('model_key = ?', $key)->where('condition_grade IN (?)', array_keys($grades)));
                $db->delete($table, $where);
                $this->insert($table, $this->statistics($sales, $until));
            }
            $this->opportunities->refreshCohorts($affected);
        });
    }

    public function executeRow($id): void
    {
        $this->executeList([$id]);
    }

    public function execute($ids): void
    {
        $this->executeList($ids);
    }

    private function refreshWinners(array $ids, string $from, string $until, array &$affected): void
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::SALES_TABLE);
        $previousItems = $db->fetchCol($db->select()->from($table, ['item_id'])->where('listing_id IN (?)', $ids));
        $items = array_unique(array_merge($previousItems, $this->listings->soldItemIds($from, $until, $ids)));
        if ($items === []) {
            return;
        }

        $before = array_column($db->fetchAll($db->select()->from($table)->where('item_id IN (?)', $items)),
            null, 'item_id');
        $after = $this->winners($this->listings->soldItems($from, $until, $items));

        $replace = [];
        $rows = [];
        foreach ($items as $item) {
            $old = $before[$item] ?? null;
            $new = $after[$item]['row'] ?? null;
            if ($old !== null) {
                $old['listing_id'] = (int)$old['listing_id'];
            }
            if ($old === $new) {
                continue;
            }
            $replace[] = $item;
            if ($new !== null) {
                $rows[] = $new;
            }
            if ($old === null || $new === null || $old['model_key'] !== $new['model_key']
                || $old['condition_grade'] !== $new['condition_grade'] || $old['final_price'] !== $new['final_price']
                || $old['sale_type'] !== $new['sale_type']
            ) {
                foreach (array_filter([$old, $new]) as $sale) {
                    $affected[$sale['model_key']][$sale['condition_grade']] = true;
                }
            }
        }
        if ($replace !== []) {
            $db->delete($table, ['item_id IN (?)' => $replace]);
            $this->insert($table, $rows);
        }
    }

    private function winners(iterable $listings): array
    {
        $sales = [];
        foreach ($listings as $listing) {
            $group = $this->eligibility->group($listing);
            if ($group === null) {
                continue;
            }

            $item = Listings::itemId($listing['external_id']);
            $previous = $sales[$item] ?? null;
            if ($previous === null || $listing['end_time'] > $previous['end_time']
                || ($listing['end_time'] === $previous['end_time'] && $listing['id'] > $previous['row']['listing_id'])
            ) {
                $sales[$item] = [
                    'row' => [
                        'item_id' => $item,
                        'listing_id' => (int)$listing['id'],
                        'model_key' => $group[0],
                        'condition_grade' => $group[1],
                        'final_price' => $listing['final_price'],
                        'sale_type' => self::saleType($listing['sale_type'] ?? null),
                    ],
                    'end_time' => $listing['end_time'],
                ];
            }
        }
        return $sales;
    }

    private function statistics(array $sales, string $computedAt): array
    {
        $groups = [];
        $bin = [];
        foreach ($sales as $sale) {
            $groups[$sale['model_key']][$sale['condition_grade']][] = (float)$sale['final_price'];
            if (($sale['sale_type'] ?? null) === 'bin') {
                $bin[$sale['model_key']][$sale['condition_grade']][] = (float)$sale['final_price'];
            }
        }

        $rows = [];
        foreach ($groups as $key => $grades) {
            foreach ($grades as $grade => $prices) {
                $binPrices = $bin[$key][$grade] ?? [];
                $rows[] = [
                    'model_key' => $key,
                    'condition_grade' => $grade,
                    'median_final_price' => self::median($prices),
                    'mean_final_price' => round(array_sum($prices) / count($prices), 8),
                    'samples' => count($prices),
                    'computed_at' => $computedAt,
                    'bin_median_final_price' => $binPrices === [] ? null : self::median($binPrices),
                    'bin_samples' => count($binPrices),
                ];
            }
        }
        return $rows;
    }

    private static function median(array $prices): float
    {
        sort($prices, SORT_NUMERIC);
        $count = count($prices);
        return ($prices[intdiv($count - 1, 2)] + $prices[intdiv($count, 2)]) / 2;
    }

    /** Auction, bin or both, as stored on the listing; anything else is kept as unknown (null). */
    private static function saleType(mixed $type): ?string
    {
        $type = strtolower(trim((string)$type));
        return in_array($type, ['auction', 'bin', 'both'], true) ? $type : null;
    }

    private function changedValuations(array $before, array $after): array
    {
        $old = [];
        foreach ($before as $row) {
            $old[$row['model_key']][$row['condition_grade']] = $row;
        }

        $affected = [];
        foreach ($after as $row) {
            $key = $row['model_key'];
            $grade = $row['condition_grade'];
            $previous = $old[$key][$grade] ?? [];
            if ((int)($previous['samples'] ?? 0) !== $row['samples']
                || (float)($previous['median_final_price'] ?? 0) !== $row['median_final_price']
                || (int)($previous['bin_samples'] ?? 0) !== $row['bin_samples']
                || (float)($previous['bin_median_final_price'] ?? 0) !== (float)$row['bin_median_final_price']
            ) {
                $affected[$key][$grade] = true;
            }
            unset($old[$key][$grade]);
        }

        foreach ($old as $key => $grades) {
            foreach (array_keys($grades) as $grade) {
                $affected[$key][$grade] = true;
            }
        }
        return $affected;
    }

    private function window(): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return [$now->modify('-365 days')->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')];
    }

    private function insert(string $table, array $rows): void
    {
        $db = $this->resource->getConnection();
        foreach (array_chunk($rows, 500) as $batch) {
            $db->insertMultiple($table, $batch);
        }
    }
}
