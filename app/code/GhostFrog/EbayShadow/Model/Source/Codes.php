<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/** Fixed code lists for grid filters, chosen by the "type" constructor argument in di.xml. */
class Codes implements OptionSourceInterface
{
    private const CODES = [
        'outcome' => ['bought', 'skipped'],
        'reason' => [
            'bought', 'no_cash', 'below_profit', 'below_roi', 'low_confidence', 'few_comparables', 'category_cap',
            'item_cap', 'stale_price', 'already_held', 'review_needed', 'extraction_pending', 'extracted_risk',
            'faulty_excluded', 'category_disabled',
        ],
        'stage' => [
            'bidding', 'lost', 'void', 'inbound', 'received', 'listed', 'outbound', 'paid_out', 'banked', 'written_off',
        ],
        'method' => ['forward', 'markdown', 'write_off'],
        'grade' => ['A', 'B', 'C', 'F'],
    ];

    public function __construct(private readonly string $type = 'reason')
    {
    }

    public function toOptionArray(): array
    {
        return array_map(static fn(string $code): array => ['value' => $code, 'label' => $code],
            self::CODES[$this->type] ?? []);
    }
}
