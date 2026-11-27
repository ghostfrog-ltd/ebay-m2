<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\ScopeInterface;

/** eBay buyers deal with eBay, never with this Magento: no sales email of any kind from the eBay store. */
class DisableEbayStoreEmails implements DataPatchInterface
{
    private const PATHS = [
        'sales_email/order/enabled', 'sales_email/order_comment/enabled', 'sales_email/invoice/enabled',
        'sales_email/invoice_comment/enabled', 'sales_email/shipment/enabled', 'sales_email/shipment_comment/enabled',
        'sales_email/creditmemo/enabled', 'sales_email/creditmemo_comment/enabled',
    ];

    public function __construct(private readonly ModuleDataSetupInterface $setup, private readonly WriterInterface $writer)
    {
    }

    public function apply(): self
    {
        $db = $this->setup->getConnection();
        $storeId = (int)$db->fetchOne($db->select()->from($this->setup->getTable('store'), ['store_id'])
            ->where('code = ?', AddEbayStoreAndStockAttributes::STORE_CODE));
        if ($storeId > 0) {
            foreach (self::PATHS as $path) {
                $this->writer->save($path, '0', ScopeInterface::SCOPE_STORES, $storeId);
            }
        }
        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddEbayStoreAndStockAttributes::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
