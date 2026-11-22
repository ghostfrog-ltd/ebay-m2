<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Comps;

use DateTimeImmutable;
use DateTimeZone;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use RuntimeException;
use Throwable;

class Index implements ActionInterface, MviewActionInterface
{
    public const ID = 'ghostfrog_ebay_comps';
    public const TABLE = 'ghostfrog_ebay_comps';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LockManagerInterface $lock,
        private readonly Listings $listings,
        private readonly Eligibility $eligibility
    ) {
    }

    public function executeFull(): void
    {
        $this->refresh(null);
    }

    public function refreshSales(array $ids): void
    {
        if ($ids !== []) {
            $this->refresh($ids);
        }
    }

    private function refresh(?array $ids): void
    {
        if (!$this->lock->lock(self::ID, 0)) {
            throw new RuntimeException('The GhostFrog comps index is already rebuilding.');
        }
        try {
            $this->rebuild($ids);
        } finally {
            $this->lock->unlock(self::ID);
        }
    }

    public function executeList(array $ids): void
    {
        // A changed or deleted listing can move out of its old model and grade.
        if ($ids !== []) {
            $this->executeFull();
        }
    }

    public function executeRow($id): void
    {
        $this->executeFull();
    }

    public function execute($ids): void
    {
        $this->executeList($ids);
    }

    private function rebuild(?array $ids): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $computedAt = $now->format('Y-m-d H:i:s');
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $db->beginTransaction();
        try {
            $scope = null;
            if ($ids !== null) {
                $scope = [];
                foreach ($this->listings->related($ids) as $listing) {
                    $group = $this->eligibility->group($listing);
                    if ($group !== null) {
                        [$key, $grade] = $group;
                        $scope[$key][$grade] = true;
                    }
                }
                if ($scope === []) {
                    $db->commit();
                    return;
                }
            }

            $sales = [];
            $modelKeys = $scope === null ? null : array_keys($scope);
            foreach ($this->listings->sold($now->modify('-365 days')->format('Y-m-d H:i:s'), $computedAt, $modelKeys) as $listing) {
                $group = $this->eligibility->group($listing);
                if ($group === null) {
                    continue;
                }
                [$key, $grade] = $group;
                $item = Listings::itemId($listing['external_id']);
                $previous = $sales[$item] ?? null;
                if ($previous === null || $listing['end_time'] > $previous['end_time']
                    || ($listing['end_time'] === $previous['end_time'] && $listing['id'] > $previous['id'])
                ) {
                    $sales[$item] = [
                        'id' => $listing['id'],
                        'key' => $key,
                        'grade' => $grade,
                        'price' => (float)$listing['final_price'],
                        'end_time' => $listing['end_time'],
                    ];
                }
            }
            $groups = [];
            foreach ($sales as $sale) {
                if ($scope === null || isset($scope[$sale['key']][$sale['grade']])) {
                    $groups[$sale['key']][$sale['grade']][] = $sale['price'];
                }
            }

            if ($scope === null) {
                $db->delete($table);
            } else {
                foreach ($scope as $key => $grades) {
                    $db->delete($table, ['model_key = ?' => $key, 'condition_grade IN (?)' => array_keys($grades)]);
                }
            }
            $rows = [];
            foreach ($groups as $key => $grades) {
                foreach ($grades as $grade => $prices) {
                    sort($prices, SORT_NUMERIC);
                    $count = count($prices);
                    $middle = intdiv($count, 2);
                    $rows[] = [
                        'model_key' => $key,
                        'condition_grade' => $grade,
                        'median_final_price' => ($prices[intdiv($count - 1, 2)] + $prices[$middle]) / 2,
                        'mean_final_price' => round(array_sum($prices) / $count, 8),
                        'samples' => $count,
                        'computed_at' => $computedAt,
                    ];
                    if (count($rows) === 500) {
                        $db->insertMultiple($table, $rows);
                        $rows = [];
                    }
                }
            }
            if ($rows !== []) {
                $db->insertMultiple($table, $rows);
            }
            $db->commit();
        } catch (Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }
}
