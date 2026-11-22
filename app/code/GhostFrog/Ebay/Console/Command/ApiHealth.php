<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Console\Command;

use GhostFrog\Ebay\Model\Api\DailyQuota;
use GhostFrog\Ebay\Model\Api\Usage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ApiHealth extends Command
{
    public function __construct(private readonly Usage $usage, private readonly DailyQuota $quota)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ghostfrog:ebay:api-health')
            ->setDescription('Show Magento call counts and stored eBay quotas')
            ->addOption('live-ebay', null, InputOption::VALUE_NONE, 'Ask eBay for quotas if the stored quota day has ended');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('live-ebay')) {
            $this->quota->refreshIfDue();
        }
        $summary = $this->usage->summary();
        $output->writeln('Magento eBay API usage for ' . $summary['date'] . ' UTC');
        $buckets = new Table($output);
        $buckets->setHeaders(['API', 'Local calls', 'Limit', 'eBay at lookup', 'Available', 'eBay reset (UTC)', 'Retry at (UTC)']);
        $services = new Table($output);
        $services->setHeaders(['Service', 'Calls']);
        foreach ($summary['buckets'] as $bucket => $state) {
            $buckets->addRow([
                $bucket, $state['used'], $state['limit'] ?? '', $state['reported_remaining'] ?? '',
                $state['remaining'] ?? '', $state['reset_at'] ? gmdate('Y-m-d H:i:s', $state['reset_at']) : '',
                $state['retry_at'] ? gmdate('Y-m-d H:i:s', $state['retry_at']) : '',
            ]);
            foreach ($state['services'] as $service => $count) {
                $services->addRow([$service, $count]);
            }
        }
        $buckets->render();
        $services->render();
        return self::SUCCESS;
    }
}
