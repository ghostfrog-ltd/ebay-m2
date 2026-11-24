<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Console\Command;

use GhostFrog\Ebay\Model\Condition\Backfill;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RequeueCondition extends Command
{
    public function __construct(private readonly Backfill $backfill)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ghostfrog:ebay:condition:requeue')
            ->setDescription('Queue missing LLM text and image checks for stored eBay listings')
            ->addOption('listing-id', null, InputOption::VALUE_REQUIRED, 'Limit the requeue to a Magento listing ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getOption('listing-id');
        $listingId = $id === null ? null : filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($listingId === false) {
            $output->writeln('<error>Listing ID must be a positive integer.</error>');
            return self::INVALID;
        }

        foreach ($this->backfill->run($listingId) as $stage => $count) {
            $output->writeln(sprintf('%s: %d queued', $stage, $count));
        }
        return self::SUCCESS;
    }
}
