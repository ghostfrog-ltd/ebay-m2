<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Plugin\Mview;

use GhostFrog\Ebay\Model\Comps\Index as CompsIndex;
use GhostFrog\Ebay\Model\Opportunities\Index as OpportunitiesIndex;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Mview\View\Changelog;
use Magento\Framework\Mview\View\ChangelogBatchWalker\IdsTableBuilder;
use Magento\Framework\Mview\View\ChangelogInterface;

/** Magento's default unsigned INT changelog and batch columns would truncate listing IDs. */
class ListingIds
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function afterCreate(Changelog $subject): void
    {
        if ($this->isListingView($subject)) {
            $db = $this->resource->getConnection();
            $table = $this->resource->getTableName($subject->getName());
            $column = $subject->getColumnName();
            if ($db->describeTable($table)[$column]['DATA_TYPE'] !== 'bigint') {
                $db->modifyColumn($table, $column, [
                    'type' => Table::TYPE_BIGINT, 'unsigned' => true, 'nullable' => false,
                    'default' => '0', 'comment' => 'Listing ID',
                ]);
            }
        }
    }

    public function afterBuild(IdsTableBuilder $subject, Table $table, ChangelogInterface $changelog): Table
    {
        if ($this->isListingView($changelog)) {
            $table->addColumn(
                $changelog->getColumnName(),
                Table::TYPE_BIGINT,
                null,
                ['unsigned' => true, 'nullable' => false],
                'Listing ID'
            );
        }
        return $table;
    }

    private function isListingView(ChangelogInterface $changelog): bool
    {
        return in_array($changelog->getViewId(), [CompsIndex::ID, OpportunitiesIndex::ID], true);
    }
}
