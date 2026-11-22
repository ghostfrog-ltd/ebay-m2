<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\Comps;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

class PriceSetting extends Value
{
    public function beforeSave()
    {
        $field = basename((string)$this->getPath());
        if (!Pricing::validSetting($this->getValue(), $field)) {
            throw new LocalizedException($field === Pricing::SELLING_FEE
                ? __('Enter a fee percentage from 0 to 100 with up to two decimal places.')
                : __('Enter a non-negative GBP amount with up to two decimal places.'));
        }

        $this->setValue(trim((string)$this->getValue()));
        return parent::beforeSave();
    }
}
