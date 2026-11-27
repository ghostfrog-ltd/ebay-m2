<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Product;

use GhostFrog\EbayLedger\Model\Config\Settings;
use GhostFrog\EbayLedger\Setup\Patch\Data\AddEbayStoreAndStockAttributes as Setup;
use InvalidArgumentException;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Eav\Api\AttributeSetRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Store\Model\StoreManagerInterface;
use Throwable;

/**
 * Creates the Magento products for a confirmed sell plan through Magento's product repository: simple products,
 * quantity 1, in the GhostFrog Stock attribute set, on the eBay website only.
 */
class Creator
{
    public const TABLE = 'ghostfrog_ebayledger_product';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ProductInterfaceFactory $productFactory,
        private readonly ProductRepositoryInterface $products,
        private readonly AttributeSetRepositoryInterface $attributeSets,
        private readonly SearchCriteriaBuilder $criteria,
        private readonly StoreManagerInterface $storeManager,
        private readonly IndexerRegistry $indexers,
        private readonly Settings $settings
    ) {
    }

    /** Products made from the unit that still exist (not replaced). */
    public function active(int $unitId): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchAll($db->select()->from($this->resource->getTableName(self::TABLE))
            ->where('stock_unit_id = ?', $unitId)->where('status <> ?', 'replaced')->order('line_number'));
    }

    /**
     * @param array $plan Confirmed plan row with decoded options.
     * @param bool $replace Delete the unit's existing unsold products first; you saw the new allocation.
     * @return list<array> The products created.
     */
    public function create(array $unit, array $plan, bool $replace = false): array
    {
        $option = $plan['options']['options'][$plan['chosen_option']] ?? null;
        if ($option === null) {
            throw new InvalidArgumentException('The plan has no confirmed option.');
        }
        $planned = Allocation::products($unit, $option);
        $existing = $this->active((int)$unit['stock_unit_id']);
        if ($existing !== []) {
            if (!$replace) {
                throw new InvalidArgumentException('This unit already has products. Review the new cost allocation and tick Replace to change them.');
            }
            $this->removeUnsold($existing);
        }

        $websiteId = (int)$this->storeManager->getWebsite(Setup::WEBSITE_CODE)->getId();
        $attributeSetId = $this->attributeSetId();
        $db = $this->resource->getConnection();
        $created = [];
        try {
            foreach ($planned as $row) {
                $product = $this->save($row, (string)$unit['reference'], $websiteId, $attributeSetId);
                $db->insert($this->resource->getTableName(self::TABLE), [
                    'stock_unit_id' => (int)$unit['stock_unit_id'],
                    'plan_id' => (int)$plan['plan_id'],
                    'product_id' => (int)$product->getId(),
                    'sku' => $row['sku'],
                    'line_number' => $row['line_number'],
                    'part_name' => $row['part_name'],
                    'parts' => json_encode($row['parts'], JSON_UNESCAPED_UNICODE),
                    'planned_price' => $row['planned_price'],
                    'allocated_cost' => $row['allocated_cost'],
                ]);
                $created[] = $row + ['product_id' => (int)$product->getId()];
            }
        } catch (Throwable $error) {
            foreach ($created as $row) {
                $this->deleteProduct((int)$row['product_id']);
                $db->delete($this->resource->getTableName(self::TABLE), ['product_id = ?' => (int)$row['product_id']]);
            }
            throw $error;
        }
        // Stock indexes update on cron; index these now so they are salable straight away.
        $this->indexers->get('cataloginventory_stock')->reindexList(array_column($created, 'product_id'));
        return $created;
    }

    private function save(array $row, string $reference, int $websiteId, int $attributeSetId): ProductInterface
    {
        try {
            $this->products->get($row['sku']);
            throw new InvalidArgumentException("A product with SKU {$row['sku']} already exists in Magento.");
        } catch (\Magento\Framework\Exception\NoSuchEntityException) {
        }
        /** @var \Magento\Catalog\Model\Product $product */
        $product = $this->productFactory->create();
        $product->setSku($row['sku'])
            ->setName($row['name'])
            ->setTypeId(Type::TYPE_SIMPLE)
            ->setAttributeSetId($attributeSetId)
            ->setPrice($row['planned_price'])
            ->setStatus(ProductStatus::STATUS_ENABLED)
            ->setVisibility(Visibility::VISIBILITY_NOT_VISIBLE)
            ->setWebsiteIds([$websiteId])
            ->setData('tax_class_id', $this->settings->productTaxClassId())
            ->setData('url_key', strtolower($row['sku']))
            ->setStockData(['use_config_manage_stock' => 0, 'manage_stock' => 1, 'qty' => 1, 'is_in_stock' => 1]);
        $product->setCustomAttribute('gf_stock_reference', $reference);
        $product->setCustomAttribute('gf_part_name', $row['part_name']);
        $product->setCustomAttribute('gf_condition_grade', $row['grade']);
        $product->setCustomAttribute('gf_allocated_cost', $row['allocated_cost']);
        $product->setCustomAttribute('gf_planned_price', $row['planned_price']);
        return $this->products->save($product);
    }

    private function removeUnsold(array $existing): void
    {
        $db = $this->resource->getConnection();
        $ids = array_filter(array_map('intval', array_column($existing, 'product_id')));
        $sold = array_filter($existing, static fn(array $row) => $row['status'] === 'sold');
        $ordered = $ids === [] ? 0 : (int)$db->fetchOne($db->select()
            ->from($this->resource->getTableName('sales_order_item'), ['COUNT(*)'])->where('product_id IN (?)', $ids));
        if ($sold !== [] || $ordered > 0) {
            throw new InvalidArgumentException('Some of this unit\'s products have already sold, so its plan can no longer change.');
        }
        foreach ($existing as $row) {
            $this->deleteProduct((int)$row['product_id']);
            $db->update($this->resource->getTableName(self::TABLE), ['status' => 'replaced', 'product_id' => null],
                ['ledger_product_id = ?' => (int)$row['ledger_product_id']]);
        }
    }

    private function deleteProduct(int $productId): void
    {
        if ($productId > 0) {
            try {
                $this->products->deleteById((string)$this->products->getById($productId)->getSku());
            } catch (\Magento\Framework\Exception\NoSuchEntityException) {
            }
        }
    }

    private function attributeSetId(): int
    {
        $sets = $this->attributeSets->getList($this->criteria
            ->addFilter('attribute_set_name', Setup::ATTRIBUTE_SET)->addFilter('entity_type_id', 4)->create())->getItems();
        $set = reset($sets);
        if (!$set) {
            throw new InvalidArgumentException('The GhostFrog Stock attribute set is missing. Run setup:upgrade.');
        }
        return (int)$set->getAttributeSetId();
    }
}
