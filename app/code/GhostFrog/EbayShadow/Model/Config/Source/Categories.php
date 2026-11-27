<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Model\Config\Source;

use GhostFrog\Ebay\Model\Collection\SourceSearches;
use Magento\Framework\Data\OptionSourceInterface;

/** GhostFrog source domains, which shadow treats as categories. */
class Categories implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        $options = [];
        foreach (array_keys(SourceSearches::DEFINITIONS) as $domain) {
            $options[] = ['value' => $domain, 'label' => $domain];
        }
        return $options;
    }
}
