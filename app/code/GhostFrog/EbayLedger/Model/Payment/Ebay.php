<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Payment;

use Magento\Payment\Model\Method\AbstractMethod;

/** The buyer paid eBay. Only Ledger's order import uses this method; it never appears at a checkout. */
class Ebay extends AbstractMethod
{
    public const CODE = 'ebay';

    protected $_code = self::CODE;
    protected $_isOffline = true;
    protected $_canUseCheckout = false;
    protected $_canUseInternal = false;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
}
