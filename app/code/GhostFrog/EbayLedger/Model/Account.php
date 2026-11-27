<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model;

use Magento\Framework\Model\AbstractModel;

class Account extends AbstractModel
{
    public const ROLE_BUYER = 'buyer';
    public const ROLE_SELLER = 'seller';
    public const ROLE_BOTH = 'both';

    public const STATUS_NOT_CONNECTED = 'not_connected';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_ERROR = 'error';
    public const STATUS_EXPIRED = 'expired';

    protected function _construct(): void
    {
        $this->_init(ResourceModel\Account::class);
    }

    public function buys(): bool
    {
        return in_array($this->getData('role'), [self::ROLE_BUYER, self::ROLE_BOTH], true);
    }

    public function sells(): bool
    {
        return in_array($this->getData('role'), [self::ROLE_SELLER, self::ROLE_BOTH], true);
    }
}
