<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ResourceModel;

use GhostFrog\Ebay\Model\Condition\Assessment;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Throwable;

class Listing
{
    public const TABLE = 'ghostfrog_ebay_auction_listings';

    private const EDITABLE_FIELDS = ['title', 'model_key', 'condition_grade', 'notes'];

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function get(int $id): array
    {
        return $this->load($id, false);
    }

    public function revision(array $listing): string
    {
        return hash('sha256', json_encode($this->editValues($listing), JSON_THROW_ON_ERROR));
    }

    public function save(int $id, array $input, string $revision): void
    {
        foreach (self::EDITABLE_FIELDS as $field) {
            if (!is_string($input[$field] ?? null)) {
                throw new LocalizedException(__('The listing form is incomplete. Please try again.'));
            }
        }
        $values = $this->editValues($input);
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $listing = $this->load($id, true);
            $changes = array_diff_assoc($values, $this->editValues($listing));
            if ($changes !== []) {
                if (!hash_equals($this->revision($listing), $revision)) {
                    throw new LocalizedException(
                        __('The listing changed while you were editing it. Review the current values and try again.')
                    );
                }
                $this->validate($changes);
                $db->update(
                    $this->resource->getTableName(self::TABLE),
                    array_map(static fn(string $value): ?string => $value === '' ? null : $value, $changes),
                    ['id = ?' => $id]
                );
            }
            $db->commit();
        } catch (Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    private function load(int $id, bool $lock): array
    {
        $db = $this->resource->getConnection();
        $select = $db->select()
            ->from($this->resource->getTableName(self::TABLE), [
                'id', 'external_id', 'source', 'status', 'sale_type', 'price_current',
                'price_bid_current', 'bids_count', 'final_price', 'end_time', 'last_seen_at', 'url',
                ...self::EDITABLE_FIELDS,
            ])
            ->where('id = ?', $id)
            ->forUpdate($lock);
        $listing = $db->fetchRow($select);
        if (!$listing) {
            throw new NoSuchEntityException(__('This listing no longer exists.'));
        }
        return $listing;
    }

    private function editValues(array $listing): array
    {
        $values = [];
        foreach (self::EDITABLE_FIELDS as $field) {
            $values[$field] = str_replace(["\r\n", "\r"], "\n", (string)$listing[$field]);
        }
        return $values;
    }

    private function validate(array $changes): void
    {
        if (mb_strlen($changes['title'] ?? '') > 255) {
            throw new LocalizedException(__('Enter a title with 255 characters or fewer.'));
        }
        if (mb_strlen($changes['model_key'] ?? '') > 255) {
            throw new LocalizedException(__('Enter a model key with 255 characters or fewer.'));
        }
        $grade = $changes['condition_grade'] ?? '';
        if ($grade !== '' && !in_array($grade, Assessment::GRADES, true)) {
            throw new LocalizedException(__('Select a valid condition grade.'));
        }
    }
}
