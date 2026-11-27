<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Sale;

use GhostFrog\EbayLedger\Model\Carrier\EbayShipping;
use GhostFrog\EbayLedger\Model\Carrier\Postage;
use GhostFrog\EbayLedger\Model\Payment\Ebay;
use GhostFrog\EbayLedger\Setup\Patch\Data\AddEbayStoreAndStockAttributes as Setup;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds a Magento order on the eBay store for one eBay order, through a quote and Magento's quote management:
 * a guest "eBay Buyer" (the buyer's real details stay on eBay), each item at the price eBay reports, postage as the
 * eBay shipping method, and the offline eBay payment method. No email is sent.
 */
class OrderBuilder
{
    public function __construct(
        private readonly QuoteFactory $quotes,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly CartManagementInterface $quoteManagement,
        private readonly ProductRepositoryInterface $products,
        private readonly OrderRepositoryInterface $orders,
        private readonly StoreManagerInterface $storeManager,
        private readonly Postage $postage
    ) {
    }

    /**
     * @param list<array> $lines Matched sale lines, each with product_id (Magento product) added.
     */
    public function create(string $ebayOrderId, array $lines): OrderInterface
    {
        $store = $this->storeManager->getStore(Setup::STORE_CODE);
        $quote = $this->quotes->create();
        $quote->setStore($store);
        $quote->setStoreId((int)$store->getId());
        $quote->setCurrency();
        $quote->setCustomerIsGuest(true);
        $quote->setCustomerEmail('ebay-' . preg_replace('/[^a-z0-9-]/i', '', $ebayOrderId) . '@ebay.invalid');
        $quote->setCustomerFirstname('eBay');
        $quote->setCustomerLastname('Buyer');
        $quote->setInventoryProcessed(false);

        $postage = 0.0;
        foreach ($lines as $line) {
            $product = $this->products->getById((int)$line['product_id'], false, (int)$store->getId(), true);
            $item = $quote->addProduct($product, 1);
            if (is_string($item)) {
                throw new LocalizedException(__('%1 could not be added to the order: %2', $line['sku'], $item));
            }
            $price = (float)$line['line_price'];
            $item->setCustomPrice($price);
            $item->setOriginalCustomPrice($price);
            $item->getProduct()->setIsSuperMode(true);
            $postage += (float)$line['postage'];
        }

        $address = [
            'firstname' => 'eBay',
            'lastname' => 'Buyer',
            'street' => ['Held on eBay', 'eBay order ' . $ebayOrderId],
            'city' => 'See eBay',
            'postcode' => 'N/A',
            'country_id' => 'GB',
            'telephone' => '0',
            'save_in_address_book' => 0,
        ];
        $quote->getBillingAddress()->addData($address);
        $shipping = $quote->getShippingAddress()->addData($address);

        $this->postage->set(round($postage, 2));
        try {
            $shipping->setCollectShippingRates(true)->collectShippingRates()
                ->setShippingMethod(EbayShipping::CODE . '_' . EbayShipping::METHOD);
            $quote->setPaymentMethod(Ebay::CODE);
            // The payment needs a saved quote to attach to.
            $this->quoteRepository->save($quote);
            $quote->getPayment()->importData(['method' => Ebay::CODE]);
            $quote->collectTotals();
            $this->quoteRepository->save($quote);
            $order = $this->quoteManagement->submit($quote);
        } finally {
            $this->postage->set(null);
        }
        if (!$order instanceof OrderInterface) {
            throw new LocalizedException(__('Magento did not create the order for eBay order %1.', $ebayOrderId));
        }
        $order->setExtOrderId($ebayOrderId);
        $order->setCanSendNewEmailFlag(false);
        $order->addCommentToStatusHistory(sprintf('Imported from eBay order %s (lines %s).', $ebayOrderId,
            implode(', ', array_column($lines, 'ebay_line_item_id'))))->setIsCustomerNotified(false);
        return $this->orders->save($order);
    }
}
