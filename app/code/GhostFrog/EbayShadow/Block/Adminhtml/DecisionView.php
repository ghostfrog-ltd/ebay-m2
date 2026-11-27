<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Block\Adminhtml;

use GhostFrog\Ebay\Model\Opportunities\ListingUrl;
use GhostFrog\EbayShadow\Model\Decisions;
use GhostFrog\EbayShadow\Model\Sales;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ResourceConnection;

/** A decision with its full evidence and settings snapshot, and its item, sale and cash movements. */
class DecisionView extends Template
{
    private ?array $decision = null;

    public function __construct(Context $context, private readonly ResourceConnection $resource, array $data = [])
    {
        parent::__construct($context, $data);
    }

    public function decision(): ?array
    {
        if ($this->decision === null) {
            $db = $this->resource->getConnection();
            $row = $db->fetchRow($db->select()->from(['d' => $this->resource->getTableName(Decisions::TABLE)])
                ->joinLeft(['p' => $this->resource->getTableName('ghostfrog_ebayshadow_pot')], 'p.pot_id = d.pot_id',
                    ['pot_name' => 'name'])
                ->where('d.decision_id = ?', (int)$this->getRequest()->getParam('id')));
            $this->decision = $row ?: [];
        }
        return $this->decision ?: null;
    }

    public function item(): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->resource->getTableName(Decisions::ITEMS))
            ->where('decision_id = ?', (int)($this->decision()['decision_id'] ?? 0)));
        return $row ?: null;
    }

    public function sale(?array $item): ?array
    {
        if ($item === null) {
            return null;
        }
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->resource->getTableName(Sales::TABLE))
            ->where('item_id = ?', (int)$item['item_id']));
        return $row ?: null;
    }

    public function movements(?array $item): array
    {
        if ($item === null) {
            return [];
        }
        $db = $this->resource->getConnection();
        return $db->fetchAll($db->select()->from($this->resource->getTableName('ghostfrog_ebayshadow_cash_movement'),
            ['occurred_at', 'type', 'amount', 'idempotency_key', 'note'])
            ->where('item_id = ?', (int)$item['item_id'])->order('movement_id ASC'));
    }

    public function listingUrl(array $decision): ?string
    {
        return ListingUrl::resolve($decision['url'] ?? null, (string)$decision['external_id']);
    }

    public function pretty(?string $json): string
    {
        $value = json_decode((string)$json, true);
        return (string)json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
