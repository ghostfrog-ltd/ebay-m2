<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\ResultFactory;
use Psr\Log\LoggerInterface;

/**
 * "eBay shipping": the postage the buyer paid on eBay, exactly. It offers a rate only while Ledger is building an
 * order for an eBay sale, so it never appears at a checkout.
 */
class EbayShipping extends AbstractCarrier implements CarrierInterface
{
    public const CODE = 'ebayshipping';
    public const METHOD = 'ebay';

    protected $_code = self::CODE;
    protected $_isFixed = true;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        private readonly ResultFactory $rateResultFactory,
        private readonly MethodFactory $rateMethodFactory,
        private readonly Postage $postage,
        array $data = []
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    public function collectRates(RateRequest $request)
    {
        $amount = $this->postage->get();
        if ($amount === null || !$this->getConfigFlag('active')) {
            return false;
        }
        $result = $this->rateResultFactory->create();
        $method = $this->rateMethodFactory->create();
        $method->setCarrier(self::CODE);
        $method->setCarrierTitle($this->getConfigData('name'));
        $method->setMethod(self::METHOD);
        $method->setMethodTitle($this->getConfigData('title'));
        $method->setPrice($amount);
        $method->setCost($amount);
        $result->append($method);
        return $result;
    }

    public function getAllowedMethods(): array
    {
        return [self::METHOD => $this->getConfigData('title')];
    }

    public function isTrackingAvailable(): bool
    {
        return false;
    }
}
