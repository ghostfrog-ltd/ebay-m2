<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Console\Command;

use GhostFrog\Ebay\Model\Api\DailyQuota;
use GhostFrog\Ebay\Model\Api\Usage;
use GhostFrog\Ebay\Model\PriceRefresh\Pause;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ApiHealth extends Command
{
    public function __construct(
        private readonly Usage $usage,
        private readonly DailyQuota $quota,
        private readonly Pause $pause
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ghostfrog:ebay:api-health')
            ->setDescription('Show Magento call counts and stored eBay quotas')
            ->addOption('live-ebay', null, InputOption::VALUE_NONE, 'Ask eBay for quotas when a refresh is due');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('live-ebay')) {
            $this->quota->refreshIfDue(itemRefresh: true);
        }
        $summary = $this->usage->summary();
        $output->writeln('Magento eBay API usage for ' . $summary['date'] . ' UTC');
        $buckets = new Table($output);
        $buckets->setHeaders([
            'API', 'Local calls', 'Limit', 'eBay at lookup', 'Lookup (UTC)', 'Available',
            'eBay reset (UTC)', 'Pause reason', 'Retry at (UTC)',
        ]);
        $services = new Table($output);
        $services->setHeaders(['Service', 'Calls']);
        foreach ($summary['buckets'] as $bucket => $state) {
            $buckets->addRow([
                $bucket, $state['used'], $state['limit'] ?? '', $state['reported_remaining'] ?? '',
                $this->date($state['checked_at']), $state['remaining'] ?? '', $this->date($state['reset_at']),
                $state['pause_reason'] ?? '', $this->date($state['retry_at']),
            ]);
            foreach ($state['services'] as $service => $count) {
                $services->addRow([$service, $count]);
            }
        }
        $buckets->render();
        $browse = $summary['buckets'][Usage::BROWSE];
        $pause = $this->pause->state();
        $refresh = new Table($output);
        $refresh->setHeaders(['Browse discovery reserve', 'Refresh usable', 'Refresh pause reason', 'Refresh retry (UTC)']);
        $refresh->addRow([
            $browse['discovery_reserve'] ?? '',
            $pause === null ? ($browse['refresh_remaining'] ?? '') : 0,
            $pause === null ? ($browse['refresh_pause_reason'] ?? '') : $pause['reason'],
            $this->date($pause === null ? $browse['refresh_retry_at'] : $pause['retry_at']),
        ]);
        $refresh->render();
        $services->render();
        return self::SUCCESS;
    }

    private function date(?int $timestamp): string
    {
        return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : '';
    }
}
