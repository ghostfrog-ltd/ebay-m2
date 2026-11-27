<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Console\Command;

use GhostFrog\Ebay\Model\ModelKey\KeySummary;
use GhostFrog\Ebay\Model\ModelKey\Rebuild;
use GhostFrog\Ebay\Model\ModelKey\Rules\RcCars;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-runs brand normalisation, item typing and keying over every stored RC listing: live, ended and sold.
 *
 * It reads only what is already stored (title and item specifics), so it makes no eBay calls, and it
 * writes only the rows whose brand, item type, key source or key would change, so running it again
 * changes nothing. The comps and opportunities indexers pick the changes up from their change logs.
 */
class ReclassifyRc extends Command
{
    public function __construct(private readonly Rebuild $rebuild)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ghostfrog:ebay:rc:reclassify')
            ->setDescription('Re-run brand, item type and model key rules over stored RC listings (no eBay calls)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the before/after summary without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool)$input->getOption('dry-run');
        $before = new KeySummary();
        $after = new KeySummary();
        $report = $this->rebuild->run(RcCars::SOURCE, true, $dryRun, null,
            static function (array $row, array $found) use ($before, $after): void {
                $before->add($row['model_key'], $row['item_type'], $row['key_source']);
                $after->add($found['model_key'], $found['item_type'] ?? null, $found['key_source'] ?? null);
            });
        $counts = $report[RcCars::SOURCE] ?? null;
        if ($counts === null) {
            $output->writeln('<error>No ' . RcCars::SOURCE . ' source is registered.</error>');
            return self::INVALID;
        }

        $was = $before->totals();
        $now = $after->totals();
        $output->writeln(sprintf('<info>%s%d RC listings checked, all statuses.</info>',
            $dryRun ? '[dry run] ' : '', $counts['checked']));
        (new Table($output))->setHeaders(['', 'Before', 'After'])->setRows([
            ['Keyed listings', $was['keyed'], $now['keyed']],
            ['Unknown listings', $was['unknown'], $now['unknown']],
            ['Unknown %', $was['unknown_percent'] . '%', $now['unknown_percent'] . '%'],
            ['Distinct keys', $was['distinct'], $now['distinct']],
            ['Singleton keys', $was['singletons'], $now['singletons']],
            ['Listings sharing a key', $was['shared'], $now['shared']],
            ['Keys with 5+ listings', $was['five_plus'], $now['five_plus']],
        ])->render();

        $rows = [];
        foreach (array_unique(array_merge(array_keys($before->types()), array_keys($after->types()))) as $type) {
            $rows[] = [$type, $before->types()[$type] ?? 0, $after->types()[$type] ?? 0];
        }
        (new Table($output))->setHeaders(['Rows per item type', 'Before', 'After'])->setRows($rows)->render();

        $rows = [];
        foreach ($after->sources() as $source => $count) {
            $rows[] = [$source, $count];
        }
        (new Table($output))->setHeaders(['Key came from', 'Keyed listings (after)'])->setRows($rows)->render();

        $beforeTop = array_map(null, array_keys($before->top()), $before->top());
        $afterTop = array_map(null, array_keys($after->top()), $after->top());
        $rows = [];
        for ($rank = 0; $rank < max(count($beforeTop), count($afterTop)); $rank++) {
            $rows[] = [$rank + 1, $beforeTop[$rank][0] ?? '', $beforeTop[$rank][1] ?? '',
                $afterTop[$rank][0] ?? '', $afterTop[$rank][1] ?? ''];
        }
        (new Table($output))->setHeaders(['#', 'Top keys before', 'Listings', 'Top keys after', 'Listings'])
            ->setRows($rows)->render();

        $output->writeln(sprintf('%s %d of %d listings%s.',
            $dryRun ? 'Would write' : 'Wrote', $counts['written'], $counts['checked'],
            $dryRun ? '; nothing was changed. Run again without --dry-run to apply' : ''));
        return self::SUCCESS;
    }
}
