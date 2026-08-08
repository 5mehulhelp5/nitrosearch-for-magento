<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Console\Command;

use NitroSearch\Search\Model\Clocks;
use NitroSearch\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento nitrosearch:clocks` — run the heartbeat now, and show both clocks.
 *
 * WITHOUT THIS THE 86,400-SECOND CLOCK IS UNTESTABLE. It fires once a day, so the
 * only ways to observe it are to wait a day or to lie to it. `--force-refresh` does
 * the second honestly: it winds `CONFIG_REFRESHED_AT` back past the interval so the
 * next run genuinely believes a refresh is due, rather than adding a bypass flag that
 * would make the test exercise a code path merchants never take.
 *
 * The report is the point as much as the run. Both clocks, their due-ness, and
 * whether the key actually changed — because "the heartbeat ran" and "the key was
 * renewed" are different facts and the whole failure mode is the first happening
 * without the second.
 */
class ClocksCommand extends Command
{
    private Clocks $clocks;
    private Settings $settings;

    public function __construct(Clocks $clocks, Settings $settings, ?string $name = null)
    {
        $this->clocks = $clocks;
        $this->settings = $settings;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('nitrosearch:clocks')
            ->setDescription('Run the NitroSearch heartbeat and report both clocks')
            ->addOption(
                'force-refresh',
                null,
                InputOption::VALUE_NONE,
                'Wind the 86400s key-refresh clock back so it is genuinely due'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('force-refresh')) {
            $this->settings->update(['CONFIG_REFRESHED_AT' => time() - 86401]);
            $output->writeln('<comment>Key-refresh clock wound back; a refresh is now due.</comment>');
        }

        $this->report($output, 'before');

        $result = $this->clocks->run();

        $output->writeln('');
        $output->writeln('  heartbeat ran : ' . ($result['ran'] ? 'yes' : 'no (nothing was due)'));
        $output->writeln('  key changed   : ' . ($result['keyChanged'] ? '<info>yes — cached pages invalidated</info>' : 'no'));
        $output->writeln('');

        $this->report($output, 'after');

        return Command::SUCCESS;
    }

    private function report(OutputInterface $output, string $label): void
    {
        $now = time();
        $poll = (int) $this->settings->get('STATUS_CHECKED_AT', 0);
        $refresh = (int) $this->settings->get('CONFIG_REFRESHED_AT', 0);
        $key = (string) $this->settings->get('SCOPED_SEARCH_KEY');

        $output->writeln("<info>{$label}</info>");
        $output->writeln('  poll clock    : ' . $this->age($now, $poll, 300));
        $output->writeln('  refresh clock : ' . $this->age($now, $refresh, 86400));
        // NEVER PRINTS THE KEY. A support command whose output a merchant pastes into
        // a ticket must not put a live credential in that ticket.
        $output->writeln('  key           : ' . ($key === '' ? 'none' : 'held (' . strlen($key) . ' chars)'));
    }

    private function age(int $now, int $stamp, int $interval): string
    {
        if ($stamp === 0) {
            return 'never run — due';
        }

        $age = $now - $stamp;

        return $age . 's ago' . ($age >= $interval ? ' — DUE' : ' (next in ' . ($interval - $age) . 's)');
    }
}
