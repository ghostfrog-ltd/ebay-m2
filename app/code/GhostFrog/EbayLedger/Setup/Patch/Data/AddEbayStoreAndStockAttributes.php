<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Model\Entity\Attribute\SetFactory as AttributeSetFactory;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\GroupFactory;
use Magento\Store\Model\ResourceModel\Group as GroupResource;
use Magento\Store\Model\ResourceModel\Store as StoreResource;
use Magento\Store\Model\ResourceModel\Website as WebsiteResource;
use Magento\Store\Model\StoreFactory;
use Magento\Store\Model\WebsiteFactory;

/**
 * The back office for real stock: an "eBay" website that no domain serves (MSI links it to the default stock as
 * it is saved), and a "GhostFrog Stock" attribute set whose attributes trace each product to its stock unit.
 */
class AddEbayStoreAndStockAttributes implements DataPatchInterface
{
    public const WEBSITE_CODE = 'ebay';
    public const STORE_CODE = 'ebay';
    public const ATTRIBUTE_SET = 'GhostFrog Stock';
    public const ATTRIBUTES = [
        'gf_stock_reference' => ['Stock Unit Reference', 'varchar', 'text'],
        'gf_part_name' => ['Part', 'varchar', 'text'],
        'gf_condition_grade' => ['Condition Grade', 'varchar', 'text'],
        'gf_allocated_cost' => ['Allocated Cost (GBP)', 'decimal', 'text'],
        'gf_planned_price' => ['Planned Sale Price (GBP)', 'decimal', 'text'],
        'gf_ebay_item_id' => ['eBay Item ID', 'varchar', 'text'],
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $setup,
        private readonly WebsiteFactory $websites,
        private readonly WebsiteResource $websiteResource,
        private readonly GroupFactory $groups,
        private readonly GroupResource $groupResource,
        private readonly StoreFactory $stores,
        private readonly StoreResource $storeResource,
        private readonly EavSetupFactory $eavSetupFactory,
        private readonly AttributeSetFactory $attributeSets
    ) {
    }

    public function apply(): self
    {
        $this->setup->startSetup();
        $this->addWebsite();
        $this->addAttributes();
        $this->setup->endSetup();
        return $this;
    }

    private function addWebsite(): void
    {
        $website = $this->websites->create();
        $this->websiteResource->load($website, self::WEBSITE_CODE, 'code');
        if ($website->getId()) {
            return;
        }
        $website->setCode(self::WEBSITE_CODE)->setName('eBay')->setSortOrder(10);
        $this->websiteResource->save($website);

        $group = $this->groups->create();
        $group->setWebsiteId((int)$website->getId())->setName('eBay Store')->setCode('ebay_store')
            ->setRootCategoryId(2);
        $this->groupResource->save($group);

        $store = $this->stores->create();
        $store->setCode(self::STORE_CODE)->setWebsiteId((int)$website->getId())->setGroupId((int)$group->getId())
            ->setName('eBay')->setSortOrder(10)->setIsActive(1);
        $this->storeResource->save($store);

        $group->setDefaultStoreId((int)$store->getId());
        $this->groupResource->save($group);
        $website->setDefaultGroupId((int)$group->getId());
        $this->websiteResource->save($website);
    }

    private function addAttributes(): void
    {
        $eav = $this->eavSetupFactory->create(['setup' => $this->setup]);
        $entityTypeId = (int)$eav->getEntityTypeId(Product::ENTITY);
        $db = $this->setup->getConnection();
        $setId = (int)$db->fetchOne($db->select()->from($this->setup->getTable('eav_attribute_set'), ['attribute_set_id'])
            ->where('entity_type_id = ?', $entityTypeId)->where('attribute_set_name = ?', self::ATTRIBUTE_SET));
        if (!$setId) {
            $set = $this->attributeSets->create();
            $set->setEntityTypeId($entityTypeId)->setAttributeSetName(self::ATTRIBUTE_SET)->validate();
            $set->save();
            $set->initFromSkeleton((int)$eav->getDefaultAttributeSetId($entityTypeId))->save();
            $setId = (int)$set->getId();
        }
        $eav->addAttributeGroup($entityTypeId, $setId, 'GhostFrog Stock', 5);
        $order = 10;
        foreach (self::ATTRIBUTES as $code => [$label, $type, $input]) {
            if (!$eav->getAttributeId($entityTypeId, $code)) {
                $eav->addAttribute($entityTypeId, $code, [
                    'type' => $type,
                    'label' => $label,
                    'input' => $input,
                    'frontend_class' => $type === 'decimal' ? 'validate-number' : '',
                    'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                    'required' => false,
                    'user_defined' => true,
                    'visible' => true,
                    'visible_on_front' => false,
                    'searchable' => false,
                    'filterable' => false,
                    'comparable' => false,
                    'used_in_product_listing' => false,
                    'is_used_in_grid' => true,
                    'is_visible_in_grid' => $code === 'gf_stock_reference',
                    'is_filterable_in_grid' => true,
                    'apply_to' => 'simple',
                ]);
            }
            $eav->addAttributeToSet($entityTypeId, $setId, 'GhostFrog Stock', $code, $order += 10);
        }
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
