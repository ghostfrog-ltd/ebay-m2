<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Console\Command;

use GhostFrog\EbayShadow\Model\Advance;
use GhostFrog\EbayShadow\Model\Decisions;
use GhostFrog\EbayShadow\Model\Extraction\Extractor;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Runs shadow jobs by hand: extract, decide, advance, or all three. They never call eBay. */
class Run extends Command
{
    public function __construct(
        private readonly State $state,
        private readonly Extractor $extractor,
        private readonly Decisions $decisions,
        private readonly Advance $advance
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ghostfrog:shadow:run')
            ->setDescription('Run GhostFrog shadow trading jobs (simulated; never bids or buys)')
            ->addArgument('job', InputArgument::OPTIONAL, 'extract, decide, advance or all', 'all')
            ->addOption('pot', null, InputOption::VALUE_REQUIRED, 'Only this pot ID (decide)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Extractions to run (extract)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(Area::AREA_CRONTAB);
        } catch (Throwable) {
        }
        $job = (string)$input->getArgument('job');
        if (in_array($job, ['extract', 'all'], true)) {
            $limit = $input->getOption('limit');
            $output->writeln('extract: ' . json_encode($this->extractor->run($limit === null ? null : (int)$limit)));
        }
        if (in_array($job, ['decide', 'all'], true)) {
            $pot = $input->getOption('pot');
            foreach ($this->decisions->run($pot === null ? null : (int)$pot) as $id => $counts) {
                $output->writeln("decide pot $id: " . json_encode($counts));
            }
        }
        if (in_array($job, ['advance', 'all'], true)) {
            $output->writeln('advance: ' . json_encode($this->advance->run()));
        }
        return self::SUCCESS;
    }
}
