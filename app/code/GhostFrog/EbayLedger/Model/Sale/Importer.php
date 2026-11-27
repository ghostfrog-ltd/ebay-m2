<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Sale;

use GhostFrog\EbayLedger\Model\Account;
use GhostFrog\EbayLedger\Model\Account\Tokens;
use GhostFrog\EbayLedger\Model\Config\Settings;
use GhostFrog\EbayLedger\Model\Ebay\FulfillmentClient;
use GhostFrog\EbayLedger\Model\ImportRuns;
use GhostFrog\EbayLedger\Model\Privacy\Redactor;
use GhostFrog\EbayLedger\Model\ResourceModel\Account as AccountResource;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/** Imports a seller account's eBay sales and keeps their Magento orders in step. Safe to run any number of times. */
class Importer
{
    private const MAX_LOOKBACK_SECONDS = 89 * 86400;

    public function __construct(
        private readonly Tokens $tokens,
        private readonly FulfillmentClient $client,
        private readonly Store $store,
        private readonly OrderSync $sync,
        private readonly ImportRuns $runs,
        private readonly Settings $settings,
        private readonly AccountResource $accountResource,
        private readonly LockManagerInterface $lock,
        private readonly LoggerInterface $logger
    ) {
    }

    /** @return array{fetched: int, created: int, updated: int, unchanged: int, orders: array<string, int>, from: string, to: string} */
    public function run(Account $account, string $trigger, ?int $days = null): array
    {
        if (!$account->sells()) {
            throw new RuntimeException(sprintf('"%s" is not set up as a seller account.', $account->getData('label')));
        }
        $lockName = 'ghostfrog_ledger_sales_' . $account->getId();
        if (!$this->lock->lock($lockName, 0)) {
            throw new RuntimeException('Sales for this account are already importing.');
        }
        $now = time();
        $last = strtotime((string)$account->getData('sales_imported_at') . ' UTC');
        $firstRun = $days !== null || !$account->getData('sales_imported_at') || $last === false;
        $from = $firstRun ? $now - ($days ?? $this->settings->firstRunDays()) * 86400
            : min($now - $this->settings->refreshDays() * 86400, $last - 86400);
        $from = gmdate('Y-m-d H:i:s', max($from, $now - self::MAX_LOOKBACK_SECONDS));
        $to = gmdate('Y-m-d H:i:s', $now);
        $runId = $this->runs->start((int)$account->getId(), 'sales', $trigger, $from, $to);
        $counts = ['fetched' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0];
        $outcomes = [];
        $errors = [];
        try {
            // Later runs read by last change, so dispatches, cancellations and refunds on older sales come through.
            $orders = $this->client->orders($this->tokens->accessToken($account), $firstRun ? 'creationdate' : 'lastmodifieddate', $from, $to);
            $orderIds = [];
            foreach ($orders as $order) {
                $raw = Redactor::clean($order);
                foreach (SaleParser::lines($order) as $line) {
                    $counts['fetched']++;
                    $counts[$this->store->upsert((int)$account->getId(), $line, $raw)]++;
                    $orderIds[$line['ebay_order_id']] = true;
                }
            }
            foreach (array_keys($orderIds) as $orderId) {
                try {
                    $outcome = $this->sync->sync((string)$orderId);
                    $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
                } catch (Throwable $error) {
                    $errors[] = "eBay order $orderId: " . $error->getMessage();
                    $this->recordError((string)$orderId, $error->getMessage());
                }
            }
            if ($errors === []) {
                $this->accountResource->getConnection()->update($this->accountResource->getMainTable(),
                    ['sales_imported_at' => $to], ['account_id = ?' => (int)$account->getId()]);
            }
            $this->runs->finish($runId, $errors === [], $counts, $errors === [] ? null : implode("\n", $errors));
        } catch (Throwable $error) {
            $this->runs->finish($runId, false, $counts, $error->getMessage());
            $this->logger->warning('GhostFrog Ledger sales import failed.', ['account_id' => $account->getId(), 'error' => $error->getMessage()]);
            throw $error;
        } finally {
            $this->lock->unlock($lockName);
        }
        if ($errors !== []) {
            throw new RuntimeException(implode("\n", $errors));
        }
        return $counts + ['orders' => $outcomes, 'from' => $from, 'to' => $to];
    }

    private function recordError(string $ebayOrderId, string $message): void
    {
        $db = $this->accountResource->getConnection();
        $db->update($this->accountResource->getTable(Store::TABLE), ['last_error' => mb_substr($message, 0, 2000)],
            ['ebay_order_id = ?' => $ebayOrderId]);
    }
}
