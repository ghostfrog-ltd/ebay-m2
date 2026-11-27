<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Controller\Adminhtml\Dashboard;

use GhostFrog\EbayLedger\Model\Profit\Csv;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;

class Export extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'GhostFrog_EbayLedger::dashboard';

    public function __construct(Context $context, private readonly FileFactory $files, private readonly Csv $csv)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        return $this->files->create('ghostfrog-ledger-' . gmdate('Y-m-d') . '.csv', $this->csv->build(), DirectoryList::VAR_DIR, 'text/csv');
    }
}
