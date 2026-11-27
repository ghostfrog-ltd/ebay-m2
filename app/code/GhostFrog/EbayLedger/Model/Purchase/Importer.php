<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Purchase;

use GhostFrog\Ebay\Api\ListingLookupInterface;
use GhostFrog\EbayLedger\Model\Account;
use GhostFrog\EbayLedger\Model\Account\Tokens;
use GhostFrog\EbayLedger\Model\Config\Settings;
use GhostFrog\EbayLedger\Model\ImportRuns;
use GhostFrog\EbayLedger\Model\Privacy\Redactor;
use GhostFrog\EbayLedger\Model\ResourceModel\Account as AccountResource;
use GhostFrog\EbayLedger\Model\StockUnit\Store;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/** Imports an account's eBay purchases as stock units. Safe to run any number of times. */
class Importer
{
    /** eBay's GetOrders starts no further back than 90 days; keep a margin for clock drift. */
    private const MAX_LOOKBACK_SECONDS = 89 * 86400 + 23 * 3600;

    public function __construct(
        private readonly Tokens $tokens,
        private readonly TradingPurchases $purchases,
        private readonly Store $store,
        private readonly ListingLookupInterface $listings,
        private readonly ImportRuns $runs,
        private readonly Settings $settings,
        private readonly AccountResource $accountResource,
        private readonly LockManagerInterface $lock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{fetched: int, created: int, updated: int, unchanged: int, skipped: int, from: string, to: string}
     */
    public function run(Account $account, string $trigger, ?int $days = null): array
    {
        if (!$account->buys()) {
            throw new RuntimeException(sprintf('"%s" is not set up as a buyer account.', $account->getData('label')));
        }
        $lockName = 'ghostfrog_ledger_purchases_' . $account->getId();
        if (!$this->lock->lock($lockName, 0)) {
            throw new RuntimeException('Purchases for this account are already importing.');
        }
        [$from, $to] = $this->window($account, $days);
        $runId = $this->runs->start((int)$account->getId(), 'purchases', $trigger, $from, $to);
        $counts = ['fetched' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        try {
            $orders = $this->purchases->orders($this->tokens->accessToken($account), $from, $to);
            $parsed = [];
            $itemIds = [];
            foreach ($orders as $order) {
                $units = OrderParser::units($order);
                $parsed[] = [$units, Redactor::clean($order)];
                foreach ($units as $unit) {
                    $itemIds[$unit['ebay_item_id']] = true;
                }
            }
            $predictions = $this->predictions(array_keys($itemIds));
            $errors = [];
            foreach ($parsed as [$units, $raw]) {
                foreach ($units as $unit) {
                    $counts['fetched']++;
                    try {
                        $result = $this->store->upsert((int)$account->getId(), $unit,
                            $predictions[$unit['ebay_item_id']] ?? null, $raw);
                        $counts[$result]++;
                    } catch (Throwable $error) {
                        $errors[] = sprintf('Order %s line %s: %s', $unit['ebay_order_id'],
                            $unit['ebay_line_item_id'], $error->getMessage());
                    }
                }
            }
            if ($errors === []) {
                $this->accountResource->getConnection()->update($this->accountResource->getMainTable(),
                    ['purchases_imported_at' => $to], ['account_id = ?' => (int)$account->getId()]);
            }
            $this->runs->finish($runId, $errors === [], $counts, $errors === [] ? null : implode("\n", $errors));
            if ($errors !== []) {
                throw new RuntimeException(implode("\n", $errors));
            }
        } catch (Throwable $error) {
            $this->runs->finish($runId, false, $counts, $error->getMessage());
            $this->logger->warning('GhostFrog Ledger purchase import failed.', [
                'account_id' => $account->getId(), 'error' => $error->getMessage(),
            ]);
            throw $error;
        } finally {
            $this->lock->unlock($lockName);
        }
        return $counts + ['from' => $from, 'to' => $to];
    }

    /** @return array{0: string, 1: string} UTC window: the first run looks back further than later runs. */
    private function window(Account $account, ?int $days): array
    {
        $now = time();
        $last = strtotime((string)$account->getData('purchases_imported_at') . ' UTC');
        if ($days !== null) {
            $from = $now - $days * 86400;
        } elseif (!$account->getData('purchases_imported_at') || $last === false) {
            $from = $now - $this->settings->firstRunDays() * 86400;
        } else {
            $from = min($now - $this->settings->refreshDays() * 86400, $last - 86400);
        }
        return [gmdate('Y-m-d H:i:s', max($from, $now - self::MAX_LOOKBACK_SECONDS)), gmdate('Y-m-d H:i:s', $now)];
    }

    private function predictions(array $itemIds): array
    {
        try {
            return $this->listings->byItemIds($itemIds);
        } catch (Throwable $error) {
            $this->logger->warning('GhostFrog Ledger could not read core predictions.', ['error' => $error->getMessage()]);
            return [];
        }
    }
}
