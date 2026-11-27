<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Comps;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Item types kept out of the ranking, for sources that classify by type (ebay-rc).
 *
 * Every other type is priced from its sold median like any other listing. An excluded type is still
 * collected and its sales still counted, but it never ranks or alerts. The list is set in
 * Stores > Configuration > GhostFrog eBay > RC Classification.
 */
final class TypePolicy
{
    public const EXCLUDED_PATH = 'ghostfrog_ebay/rc/excluded_types';

    /** @var array<string, true>|null */
    private ?array $excluded = null;

    public function __construct(private readonly ScopeConfigInterface $config)
    {
    }

    public function isExcluded(?string $itemType): bool
    {
        if ($itemType === null || $itemType === '') {
            return false;
        }
        $this->excluded ??= array_fill_keys(
            array_filter(array_map('trim', explode(',', (string)$this->config->getValue(self::EXCLUDED_PATH)))),
            true
        );
        return isset($this->excluded[$itemType]);
    }
}
