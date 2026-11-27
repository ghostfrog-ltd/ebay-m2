<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model;

use Magento\Framework\App\ResourceConnection;

/** A log of each import, shown on the Accounts grid. */
class ImportRuns
{
    private const TABLE = 'ghostfrog_ebayledger_import_run';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function start(?int $accountId, string $kind, string $trigger, ?string $from, ?string $to): int
    {
        $db = $this->resource->getConnection();
        $db->insert($this->resource->getTableName(self::TABLE), [
            'account_id' => $accountId,
            'kind' => $kind,
            'trigger' => $trigger,
            'window_from' => $from,
            'window_to' => $to,
            'started_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int)$db->lastInsertId($this->resource->getTableName(self::TABLE));
    }

    /** @param array{fetched?: int, created?: int, updated?: int} $counts */
    public function finish(int $runId, bool $ok, array $counts, ?string $message = null): void
    {
        $this->resource->getConnection()->update($this->resource->getTableName(self::TABLE), [
            'status' => $ok ? 'ok' : 'error',
            'fetched' => $counts['fetched'] ?? 0,
            'created' => $counts['created'] ?? 0,
            'updated' => $counts['updated'] ?? 0,
            'message' => $message === null ? null : mb_substr($message, 0, 2000),
            'finished_at' => gmdate('Y-m-d H:i:s'),
        ], ['run_id = ?' => $runId]);
    }
}
