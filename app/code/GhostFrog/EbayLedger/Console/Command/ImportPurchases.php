<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Console\Command;

use GhostFrog\EbayLedger\Model\Account\Tokens;
use GhostFrog\EbayLedger\Model\Purchase\ImportAll;
use GhostFrog\EbayLedger\Model\Purchase\Importer;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class ImportPurchases extends Command
{
    public function __construct(
        private readonly State $state,
        private readonly ImportAll $all,
        private readonly Importer $importer,
        private readonly Tokens $tokens
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ghostfrog:ledger:import-purchases')
            ->setDescription('Import eBay purchases as Ledger stock units (read-only; safe to rerun).')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Ledger account ID; all buyer accounts when left out')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Look back this many days (at most 90)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(Area::AREA_CRONTAB);
        } catch (Throwable) {
        }
        $days = $input->getOption('days') === null ? null : max(1, min(90, (int)$input->getOption('days')));
        if ($input->getOption('account') !== null) {
            $account = $this->tokens->load((int)$input->getOption('account'));
            $results = [(string)$account->getData('label') => $this->safeRun(fn() => $this->importer->run($account, 'cli', $days))];
        } else {
            $results = $this->all->run('cli');
        }
        if ($results === []) {
            $output->writeln('<comment>No connected buyer accounts.</comment>');
        }
        $failed = false;
        foreach ($results as $label => $result) {
            if (is_string($result)) {
                $failed = true;
                $output->writeln(sprintf('<error>%s: %s</error>', $label, $result));
                continue;
            }
            $output->writeln(sprintf('%s: %d units from eBay (%s to %s UTC): %d new, %d updated, %d unchanged, %d cancelled skipped.',
                $label, $result['fetched'], $result['from'], $result['to'], $result['created'], $result['updated'],
                $result['unchanged'], $result['skipped']));
        }
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    private function safeRun(callable $run): array|string
    {
        try {
            return $run();
        } catch (Throwable $error) {
            return $error->getMessage();
        }
    }
}
