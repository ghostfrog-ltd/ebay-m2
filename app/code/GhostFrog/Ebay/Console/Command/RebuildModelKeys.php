<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Console\Command;

use GhostFrog\Ebay\Model\ModelKey\Rebuild;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RebuildModelKeys extends Command
{
    public function __construct(private readonly Rebuild $rebuild)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ghostfrog:ebay:model-key:rebuild')
            ->setDescription('Classify stored eBay listings whose model key is missing or unknown')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Limit to one source domain, e.g. ebay-ipod')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Also recompute listings that already have a key')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool)$input->getOption('dry-run');
        $report = $this->rebuild->run(
            $input->getOption('source'),
            (bool)$input->getOption('all'),
            $dryRun,
            static function (string $source, array $counts) use ($output, $dryRun): void {
                $output->writeln(sprintf('%s%s: %d checked, %d newly keyed, %d changed, %d still unknown',
                    $dryRun ? '[dry run] ' : '', $source, $counts['checked'], $counts['keyed'],
                    $counts['changed'], $counts['unknown']));
            }
        );
        if ($report === []) {
            $output->writeln('<error>No matching source.</error>');
            return self::INVALID;
        }
        return self::SUCCESS;
    }
}
