<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Indexing;

use GhostFrog\Ebay\Model\ResourceModel\Listing;
use Magento\Framework\DB\Ddl\Trigger;
use Magento\Framework\Mview\View\Subscription;
use Magento\Framework\Mview\ViewInterface;

class ReviewSubscription extends Subscription
{
    public function getEntityColumn(string $prefix, ViewInterface $view): string
    {
        $externalId = parent::getEntityColumn($prefix, $view);
        $table = $this->connection->quoteIdentifier($this->resource->getTableName(Listing::TABLE));
        return "COALESCE((SELECT listing.id FROM $table AS listing WHERE listing.external_id = $externalId), 0)";
    }

    protected function buildStatement(string $event, ViewInterface $view): string
    {
        $statement = parent::buildStatement($event, $view);
        if ($event === Trigger::EVENT_UPDATE) {
            $previous = parent::buildStatement(Trigger::EVENT_DELETE, $view);
            $statement .= ' IF NOT (OLD.external_id <=> NEW.external_id) THEN ' . $previous . ' END IF;';
        }
        return $statement;
    }
}
