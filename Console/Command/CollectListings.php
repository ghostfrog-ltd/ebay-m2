<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Console\Command;

use GhostFrog\Ebay\Model\Collection\Collector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CollectListings extends Command
{
    public function __construct(private readonly Collector $collector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ghostfrog:ebay:collect')
            ->setDescription('Collect eBay listings for due GhostFrog sources')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source domain, such as ebay-apple')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show source timing without calling eBay or saving listings');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $results = $this->collector->run($input->getOption('source'), $input->getOption('dry-run'));
        $code = self::SUCCESS;
        foreach ($results as $source => $result) {
            $output->writeln(
                $source . ' ' . json_encode($result, JSON_THROW_ON_ERROR),
                OutputInterface::OUTPUT_RAW
            );
            if (in_array($result['status'], ['failed', 'missing_or_disabled'], true)) {
                $code = self::FAILURE;
            }
        }
        if ($results === []) {
            $output->writeln('No enabled eBay collection sources.');
        }
        return $code;
    }
}
