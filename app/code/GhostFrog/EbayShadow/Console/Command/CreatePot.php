<?php

declare(strict_types=1);

namespace GhostFrog\EbayShadow\Console\Command;

use GhostFrog\EbayShadow\Model\Pots;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CreatePot extends Command
{
    public function __construct(private readonly Pots $pots)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ghostfrog:shadow:pot:create')
            ->setDescription('Create a running shadow pot of simulated capital')
            ->addArgument('name', InputArgument::REQUIRED)
            ->addArgument('capital', InputArgument::REQUIRED, 'Starting capital in GBP');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $capital = (float)$input->getArgument('capital');
        if ($capital <= 0) {
            $output->writeln('<error>Capital must be above zero.</error>');
            return self::FAILURE;
        }
        $id = $this->pots->create((string)$input->getArgument('name'), $capital, gmdate('Y-m-d H:i:s'));
        $output->writeln("Created pot $id.");
        return self::SUCCESS;
    }
}
