<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Console\Command;

use GhostFrog\EbayLedger\Model\Account\Tokens;
use GhostFrog\EbayLedger\Model\Sale\ImportAll;
use GhostFrog\EbayLedger\Model\Sale\Importer;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class ImportSales extends Command
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
        $this->setName('ghostfrog:ledger:import-sales')
            ->setDescription('Import eBay sales as Magento orders (read-only on eBay; safe to rerun).')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Ledger account ID; all seller accounts when left out')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Look back this many days by sale date (at most 90)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(Area::AREA_CRONTAB);
        } catch (Throwable) {
        }
        if ($input->getOption('account') !== null) {
            $account = $this->tokens->load((int)$input->getOption('account'));
            $days = $input->getOption('days') === null ? null : max(1, min(90, (int)$input->getOption('days')));
            try {
                $results = [(string)$account->getData('label') => $this->importer->run($account, 'cli', $days)];
            } catch (Throwable $error) {
                $results = [(string)$account->getData('label') => $error->getMessage()];
            }
        } else {
            $results = $this->all->run('cli');
        }
        $failed = false;
        foreach ($results as $label => $result) {
            if (is_string($result)) {
                $failed = true;
                $output->writeln(sprintf('<error>%s: %s</error>', $label, $result));
                continue;
            }
            if (isset($result['payouts'])) {
                $output->writeln(sprintf('%s: %d eBay transactions (%s to %s UTC): %d new fee lines, %d updated, %d payouts, %d linked.',
                    $label, $result['fetched'], $result['from'], $result['to'], $result['created'], $result['updated'],
                    $result['payouts'], $result['linked']));
                continue;
            }
            $orders = implode(', ', array_map(static fn($k, $v) => "$v $k", array_keys($result['orders']), $result['orders'])) ?: 'none';
            $output->writeln(sprintf('%s: %d sale lines (%s to %s UTC): %d new, %d updated, %d unchanged. Orders: %s.',
                $label, $result['fetched'], $result['from'], $result['to'], $result['created'], $result['updated'],
                $result['unchanged'], $orders));
        }
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
