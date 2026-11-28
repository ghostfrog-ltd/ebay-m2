<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Config\Source;

use GhostFrog\Ebay\Model\ModelKey\Rules\ConsoleAccessories;
use Magento\Framework\Data\OptionSourceInterface;

/** The consoles accessories are keyed for, as the Accessories grid filters them. */
class AccessoryPlatforms implements OptionSourceInterface
{
    private const LABELS = [
        'ps5' => 'PlayStation 5',
        'ps4' => 'PlayStation 4',
        'ps3' => 'PlayStation 3',
        'ps-vita' => 'PS Vita',
        'xbox-series' => 'Xbox Series X|S',
        'xbox-one' => 'Xbox One',
        'xbox-360' => 'Xbox 360',
        'xbox' => 'Xbox (any)',
        'switch-2' => 'Switch 2',
        'switch' => 'Switch',
    ];

    public function toOptionArray(): array
    {
        return array_map(
            static fn(string $platform): array => ['value' => $platform, 'label' => __(self::LABELS[$platform] ?? $platform)],
            ConsoleAccessories::platforms()
        );
    }
}
