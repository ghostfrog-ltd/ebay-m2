<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Ui\MassAction;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Component\MassAction\Filter;

/**
 * Resolves the IDs a grid mass action applies to, honouring the grid's filters for "Select All".
 * Filter::getCollection() needs a resource model, which the module's SearchResult grid collections
 * do not have, so the IDs are read from the grid data provider after Magento applies the selection.
 */
class SelectedIds
{
    public function __construct(private readonly Filter $filter, private readonly RequestInterface $request)
    {
    }

    /** @return int[] */
    public function get(): array
    {
        $selected = $this->request->getParam(Filter::SELECTED_PARAM);
        $excluded = $this->request->getParam(Filter::EXCLUDED_PARAM);
        if ($excluded !== 'false' && empty($selected) && empty($excluded)) {
            throw new LocalizedException(__('An item needs to be selected. Select and try again.'));
        }

        $component = $this->filter->getComponent();
        if ($excluded === 'false') {
            $this->filter->prepareComponent($component);
        } else {
            $this->filter->applySelectionOnTargetProvider();
        }
        $provider = $component->getContext()->getDataProvider();
        $provider->setLimit(0, false);
        $ids = [];
        foreach ($provider->getSearchResult()->getItems() as $item) {
            $ids[] = (int)$item->getId();
        }
        return $ids;
    }
}
