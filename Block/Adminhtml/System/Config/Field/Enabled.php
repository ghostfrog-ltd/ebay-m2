<?php
declare(strict_types=1);

namespace GhostFrog\Ebay\Block\Adminhtml\System\Config\Field;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class Enabled extends Field
{
    protected $_template = 'GhostFrog_Ebay::system/config/enabled.phtml';

    protected function _getElementHtml(AbstractElement $element): string
    {
        $this->setData('element', $element);

        return $this->_toHtml();
    }
}
