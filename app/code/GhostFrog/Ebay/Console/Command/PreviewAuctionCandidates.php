<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Console\Command;

use GhostFrog\Ebay\Model\Api\Usage;
use GhostFrog\Ebay\Model\PriceRefresh\Candidates;
use GhostFrog\Ebay\Model\PriceRefresh\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PreviewAuctionCandidates extends Command
{
    public function __construct(
        private readonly Candidates $candidates,
        private readonly Settings $settings,
        private readonly Usage $usage
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ghostfrog:ebay:opportunities:preview-auctions')
            ->setDescription('Preview stale near-close auction candidates from stored data without calling eBay');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $quota = $this->usage->quota(Usage::BROWSE);
        $result = [
            'enabled' => $this->settings->prequalificationEnabled(),
            'api_enabled' => $this->settings->enabled(),
            'window_hours' => $this->settings->candidateHours(),
            'batch_cap' => $this->settings->candidateBatch(),
            'window_budget' => $this->settings->candidateBudget(),
            'window_calls' => $quota['candidate_calls'],
            'browse_refresh_remaining' => $quota['refresh_remaining'],
            'browse_pause_reason' => $quota['refresh_pause_reason'],
            'opportunity_headroom' => $this->settings->refreshBatch(),
            'reset_at' => $quota['reset_at'] === null ? null : gmdate(DATE_ATOM, $quota['reset_at']),
        ] + $this->candidates->scan($this->settings->candidateBatch());
        $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        return self::SUCCESS;
    }
}
