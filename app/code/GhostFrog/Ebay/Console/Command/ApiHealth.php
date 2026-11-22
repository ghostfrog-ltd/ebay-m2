<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Console\Command;

use GhostFrog\Ebay\Model\Api\Usage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ApiHealth extends Command
{
    public function __construct(private readonly Usage $usage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ghostfrog:ebay:api-health')
            ->setDescription('Show Magento eBay call counts, daily budgets and quota pauses');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $summary = $this->usage->summary();
        $output->writeln('Magento eBay API usage for ' . $summary['date'] . ' UTC');
        $buckets = new Table($output);
        $buckets->setHeaders(['API', 'Calls', 'Budget', 'Available', 'Retry at (UTC)']);
        $services = new Table($output);
        $services->setHeaders(['Service', 'Calls']);
        foreach ($summary['buckets'] as $bucket => $state) {
            $buckets->addRow([
                $bucket, $state['used'], $state['limit'], $state['remaining'],
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
