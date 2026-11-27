<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Finance;

use GhostFrog\EbayLedger\Model\Account;
use GhostFrog\EbayLedger\Model\Account\Tokens;
use GhostFrog\EbayLedger\Model\Config\Settings;
use GhostFrog\EbayLedger\Model\Ebay\FinancesClient;
use GhostFrog\EbayLedger\Model\ImportRuns;
use GhostFrog\EbayLedger\Model\Privacy\Redactor;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Lock\LockManagerInterface;
use RuntimeException;
use Throwable;

/**
 * Imports a seller account's eBay fees, credits and payouts into Ledger's own tables (never into the orders),
 * keyed on eBay's transaction IDs, and links each fee to its sale, product and Magento order where it can.
 */
class Importer
{
    private const MAX_LOOKBACK_SECONDS = 89 * 86400;

    public function __construct(
        private readonly Tokens $tokens,
        private readonly FinancesClient $client,
        private readonly ImportRuns $runs,
        private readonly Settings $settings,
        private readonly ResourceConnection $resource,
        private readonly LockManagerInterface $lock
    ) {
    }

    /** @return array{fetched: int, created: int, updated: int, payouts: int, linked: int, from: string, to: string} */
    public function run(Account $account, string $trigger): array
    {
        if (!$account->sells()) {
            throw new RuntimeException(sprintf('"%s" is not set up as a seller account.', $account->getData('label')));
        }
        $lockName = 'ghostfrog_ledger_finances_' . $account->getId();
        if (!$this->lock->lock($lockName, 0)) {
            throw new RuntimeException('Fees for this account are already importing.');
        }
        $now = time();
        $last = strtotime((string)$account->getData('finances_imported_at') . ' UTC');
        $from = !$account->getData('finances_imported_at') || $last === false
            ? $now - $this->settings->firstRunDays() * 86400
            : min($now - $this->settings->refreshDays() * 86400, $last - 86400);
        $from = gmdate('Y-m-d H:i:s', max($from, $now - self::MAX_LOOKBACK_SECONDS));
        $to = gmdate('Y-m-d H:i:s', $now);
        $runId = $this->runs->start((int)$account->getId(), 'finances', $trigger, $from, $to);
        $counts = ['fetched' => 0, 'created' => 0, 'updated' => 0, 'payouts' => 0, 'linked' => 0];
        $db = $this->resource->getConnection();
        try {
            $token = $this->tokens->accessToken($account);
            foreach ($this->client->transactions($token, $from, $to) as $transaction) {
                $counts['fetched']++;
                $raw = json_encode(Redactor::clean($transaction), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                foreach (TransactionParser::fees($transaction) as $fee) {
                    // MySQL counts 1 for an insert and 2 for an update that changed something.
                    $affected = $db->insertOnDuplicate($this->resource->getTableName('ghostfrog_ebayledger_fee'),
                        $fee + ['account_id' => (int)$account->getId(), 'raw_payload' => $raw, 'imported_at' => gmdate('Y-m-d H:i:s')],
                        ['amount', 'payout_id', 'booked_at', 'raw_payload']);
                    $counts[$affected === 1 ? 'created' : 'updated'] += $affected > 0 ? 1 : 0;
                }
                if ($credit = TransactionParser::saleCredit($transaction)) {
                    $db->insertOnDuplicate($this->resource->getTableName('ghostfrog_ebayledger_sale_payout'), $credit,
                        ['payout_id', 'net_amount', 'booked_at']);
                }
            }
            foreach ($this->client->payouts($token, $from, $to) as $payout) {
                if (empty($payout['payoutId'])) {
                    continue;
                }
                $counts['payouts']++;
                $db->insertOnDuplicate($this->resource->getTableName('ghostfrog_ebayledger_payout'), [
                    'payout_id' => (string)$payout['payoutId'],
                    'account_id' => (int)$account->getId(),
                    'amount' => round((float)($payout['amount']['value'] ?? 0), 2),
                    'currency' => (string)($payout['amount']['currency'] ?? 'GBP'),
                    'status' => $payout['payoutStatus'] ?? null,
                    'payout_at' => isset($payout['payoutDate']) ? gmdate('Y-m-d H:i:s', strtotime((string)$payout['payoutDate'])) : null,
                    'imported_at' => gmdate('Y-m-d H:i:s'),
                ], ['amount', 'status', 'payout_at']);
            }
            $counts['linked'] = $this->link();
            $db->update($this->resource->getTableName('ghostfrog_ebayledger_account'), ['finances_imported_at' => $to],
                ['account_id = ?' => (int)$account->getId()]);
            $this->runs->finish($runId, true, $counts);
        } catch (Throwable $error) {
            $this->runs->finish($runId, false, $counts, $error->getMessage());
            throw $error;
        } finally {
            $this->lock->unlock($lockName);
        }
        return $counts + ['from' => $from, 'to' => $to];
    }

    /**
     * Links unlinked fees to their sale line: by order and line where eBay gives the line, otherwise to the order's
     * first line (fees such as postage labels are per order). Also run after sales import, as sales may come later.
     */
    public function link(): int
    {
        $db = $this->resource->getConnection();
        $fees = $this->resource->getTableName('ghostfrog_ebayledger_fee');
        $lines = $this->resource->getTableName('ghostfrog_ebayledger_sale_line');
        $linked = $db->query("UPDATE $fees f JOIN $lines l ON l.ebay_order_id = f.ebay_order_id AND l.ebay_line_item_id = f.ebay_line_item_id
            SET f.sale_line_id = l.sale_line_id, f.ledger_product_id = l.ledger_product_id, f.magento_order_id = l.magento_order_id
            WHERE f.ebay_order_id IS NOT NULL AND (f.sale_line_id IS NULL OR f.ledger_product_id <=> NULL OR f.magento_order_id <=> NULL)")->rowCount();
        $linked += $db->query("UPDATE $fees f JOIN $lines l ON l.sale_line_id = (
                SELECT MIN(x.sale_line_id) FROM $lines x WHERE x.ebay_order_id = f.ebay_order_id
                AND x.match_status IN ('matched', 'linked'))
            SET f.sale_line_id = l.sale_line_id, f.ledger_product_id = l.ledger_product_id, f.magento_order_id = l.magento_order_id
            WHERE f.ebay_order_id IS NOT NULL AND f.ebay_line_item_id IS NULL AND f.sale_line_id IS NULL")->rowCount();
        return $linked;
    }
}
