<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Sale;

use GhostFrog\EbayLedger\Model\Config\Settings;
use GhostFrog\EbayLedger\Model\StockUnit\Status;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsExtensionInterfaceFactory;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterfaceFactory;
use Magento\Sales\Api\Data\CreditmemoItemCreationInterfaceFactory;
use Magento\Sales\Api\InvoiceOrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\RefundOrderInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use Throwable;

/**
 * Keeps the Magento order for an eBay order in step with eBay: creates it once every line is matched or ignored,
 * invoices it when paid, ships it when eBay shows it dispatched, and cancels or refunds it as eBay does.
 * Products and stock units follow: sold, back for sale when cancelled before dispatch, or refunded.
 */
class OrderSync
{
    private const LINES = 'ghostfrog_ebayledger_sale_line';
    private const PRODUCTS = 'ghostfrog_ebayledger_product';
    private const UNITS = 'ghostfrog_ebayledger_stock_unit';
    private const PAID = ['PAID', 'PARTIALLY_REFUNDED', 'FULLY_REFUNDED'];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly OrderBuilder $builder,
        private readonly OrderRepositoryInterface $orders,
        private readonly OrderManagementInterface $orderManagement,
        private readonly InvoiceOrderInterface $invoiceOrder,
        private readonly ShipOrderInterface $shipOrder,
        private readonly RefundOrderInterface $refundOrder,
        private readonly CreditmemoItemCreationInterfaceFactory $creditmemoItems,
        private readonly CreditmemoCreationArgumentsInterfaceFactory $creditmemoArguments,
        private readonly CreditmemoCreationArgumentsExtensionInterfaceFactory $creditmemoExtensions,
        private readonly ProductRepositoryInterface $products,
        private readonly Settings $settings
    ) {
    }

    /** @return string What happened: waiting, ignored, created, updated or unchanged. */
    public function sync(string $ebayOrderId): string
    {
        $db = $this->resource->getConnection();
        $lines = $db->fetchAll($db->select()->from(['l' => $this->resource->getTableName(self::LINES)])
            ->joinLeft(['p' => $this->resource->getTableName(self::PRODUCTS)], 'p.ledger_product_id = l.ledger_product_id',
                ['product_id', 'stock_unit_id', 'product_sku' => 'sku'])
            ->where('l.ebay_order_id = ?', $ebayOrderId)->order('l.sale_line_id'));
        if ($lines === []) {
            return 'unchanged';
        }
        $orderId = (int)max(array_column($lines, 'magento_order_id'));
        if ($orderId === 0) {
            $orderId = (int)$db->fetchOne($db->select()->from($this->resource->getTableName('sales_order'), ['entity_id'])
                ->where('ext_order_id = ?', $ebayOrderId));
        }
        $matched = array_values(array_filter($lines, static fn($line) => in_array($line['match_status'], ['matched', 'linked'], true)));
        if ($orderId === 0) {
            if (array_filter($lines, static fn($line) => $line['match_status'] === 'unmatched') !== []) {
                return 'waiting';
            }
            if ($matched === [] || $lines[0]['cancelled']) {
                return 'ignored';
            }
            $order = $this->builder->create($ebayOrderId, $matched);
            $orderId = (int)$order->getEntityId();
            $db->update($this->resource->getTableName(self::LINES), ['magento_order_id' => $orderId, 'last_error' => null],
                ['sale_line_id IN (?)' => array_column($matched, 'sale_line_id')]);
            foreach ($matched as $line) {
                $this->markProduct((int)$line['ledger_product_id'], 'sold', $line['ebay_item_id']);
            }
            $this->refreshUnits(array_column($matched, 'stock_unit_id'));
            $this->update($orderId, $matched);
            return 'created';
        }
        return $this->update($orderId, $matched) ? 'updated' : 'unchanged';
    }

    private function update(int $orderId, array $lines): bool
    {
        /** @var Order $order */
        $order = $this->orders->get($orderId);
        $changed = false;
        $cancelled = (bool)($lines[0]['cancelled'] ?? false);
        $paid = in_array($lines[0]['payment_status'] ?? '', self::PAID, true);

        if ($cancelled && !$order->hasInvoices() && $order->canCancel()) {
            $this->orderManagement->cancel($orderId);
            foreach ($lines as $line) {
                $this->markProduct((int)$line['ledger_product_id'], 'active', null);
            }
            $this->refreshUnits(array_column($lines, 'stock_unit_id'));
            return true;
        }
        if ($paid && $order->canInvoice()) {
            $this->invoiceOrder->execute($orderId, true, [], false);
            $order = $this->orders->get($orderId);
            if ($order->getState() === Order::STATE_PROCESSING) {
                $order->setStatus($this->settings->importedOrderStatus());
                $this->orders->save($order);
            }
            $changed = true;
        }
        $dispatched = array_filter($lines, static fn($line) => $line['fulfillment_status'] === 'FULFILLED') !== [];
        if ($dispatched && !$cancelled && $order->canShip()) {
            $this->shipOrder->execute($orderId, [], false);
            $this->resource->getConnection()->update($this->resource->getTableName(self::LINES), ['shipped' => 1],
                ['sale_line_id IN (?)' => array_column($lines, 'sale_line_id')]);
            $order = $this->orders->get($orderId);
            $changed = true;
        }

        $owed = $cancelled ? (float)$order->getTotalPaid()
            : round(array_sum(array_map(static fn($line) => (float)$line['refunded'], $lines)), 2);
        $due = round($owed - (float)$order->getTotalRefunded(), 2);
        if ($due > 0.009 && $order->canCreditmemo()) {
            $this->refund($order, $lines, $due, $cancelled);
            $changed = true;
        }
        return $changed;
    }

    /**
     * Refunds what eBay refunded since the last import. A full refund credits every item and the postage, and puts
     * items back in stock if they had not been dispatched; anything less is an adjustment refund.
     */
    private function refund(Order $order, array $lines, float $due, bool $cancelled): void
    {
        $remaining = round((float)$order->getTotalPaid() - (float)$order->getTotalRefunded(), 2);
        $full = $cancelled || $due >= $remaining - 0.009;
        $shipped = $order->hasShipments();
        $arguments = $this->creditmemoArguments->create();
        $items = [];
        if ($full) {
            $returnIds = [];
            $value = 0.0;
            foreach ($order->getAllItems() as $item) {
                $qty = (float)$item->getQtyInvoiced() - (float)$item->getQtyRefunded();
                if ($qty > 0) {
                    $items[] = $this->creditmemoItems->create()->setOrderItemId((int)$item->getItemId())->setQty($qty);
                    $returnIds[] = (int)$item->getItemId();
                    $value += $qty * (float)$item->getPrice();
                }
            }
            $shipping = (float)$order->getShippingInvoiced() - (float)$order->getShippingRefunded();
            $arguments->setShippingAmount($shipping);
            // Earlier partial refunds were adjustments; hold them back so the total is what eBay refunded.
            $held = round($value + $shipping - $due, 2);
            if ($held > 0.009) {
                $arguments->setAdjustmentNegative($held);
            }
            if (!$shipped) {
                $extension = $this->creditmemoExtensions->create();
                $extension->setReturnToStockItems($returnIds);
                $arguments->setExtensionAttributes($extension);
            }
        } else {
            foreach ($order->getAllItems() as $item) {
                $items[] = $this->creditmemoItems->create()->setOrderItemId((int)$item->getItemId())->setQty(0);
            }
            $arguments->setShippingAmount(0);
            $arguments->setAdjustmentPositive($due);
        }
        $this->refundOrder->execute((int)$order->getEntityId(), $items, false, false, null, $arguments);
        if ($full) {
            foreach ($lines as $line) {
                $this->markProduct((int)$line['ledger_product_id'], $shipped ? 'refunded' : 'active', null);
            }
            $this->refreshUnits(array_column($lines, 'stock_unit_id'));
        }
    }

    private function markProduct(int $ledgerProductId, string $status, ?string $ebayItemId): void
    {
        if ($ledgerProductId <= 0) {
            return;
        }
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::PRODUCTS);
        $row = ['status' => $status];
        if ($ebayItemId !== null) {
            $row['ebay_item_id'] = $ebayItemId;
        }
        $db->update($table, $row, ['ledger_product_id = ?' => $ledgerProductId]);
        $productId = (int)$db->fetchOne($db->select()->from($table, ['product_id'])->where('ledger_product_id = ?', $ledgerProductId));
        if ($ebayItemId !== null && $productId > 0) {
            try {
                $product = $this->products->getById($productId, true, 0, true);
                $product->setCustomAttribute('gf_ebay_item_id', $ebayItemId);
                $this->products->save($product);
            } catch (Throwable) {
                // The item ID is also on Ledger's product row; the attribute is a convenience.
            }
        }
    }

    /** A unit is sold when all its products are, partly sold when some are. */
    public function refreshUnits(array $unitIds): void
    {
        $db = $this->resource->getConnection();
        foreach (array_unique(array_filter(array_map('intval', $unitIds))) as $unitId) {
            $statuses = $db->fetchCol($db->select()->from($this->resource->getTableName(self::PRODUCTS), ['status'])
                ->where('stock_unit_id = ?', $unitId)->where('status <> ?', 'replaced'));
            if ($statuses === []) {
                continue;
            }
            $sold = count(array_filter($statuses, static fn($status) => $status === 'sold'));
            $status = match (true) {
                $sold === count($statuses) => Status::SOLD,
                $sold > 0 || in_array('refunded', $statuses, true) => Status::PARTLY_SOLD,
                default => null,
            };
            $current = $db->fetchOne($db->select()->from($this->resource->getTableName(self::UNITS), ['status'])
                ->where('stock_unit_id = ?', $unitId));
            $status ??= in_array($current, [Status::SOLD, Status::PARTLY_SOLD], true) ? Status::LISTED : $current;
            $db->update($this->resource->getTableName(self::UNITS), ['status' => $status], ['stock_unit_id = ?' => $unitId]);
        }
    }
}
