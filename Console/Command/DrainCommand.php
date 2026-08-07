<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Console\Command;

use NitroSearch\Search\Model\Drain;
use NitroSearch\Search\Model\Outbox;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento nitrosearch:drain` — send what is queued, or show what would be sent.
 *
 * `--dry-run` assembles real batches from the real queue and sends nothing, putting
 * the claimed rows back so that inspecting the queue does not consume it. That makes
 * the whole chain — changelog to outbox to serializer to batch — inspectable on a
 * merchant's own catalogue before a single request leaves their server.
 *
 * `--full` enqueues the entire catalogue first. This is the full walk, and it is the
 * correctness argument for the whole sync: change detection catches most things and
 * provably not all of them, so what makes the index eventually right is re-sending
 * everything on a schedule, depending on no signal having fired.
 */
class DrainCommand extends Command
{
    private Drain $drain;
    private Outbox $outbox;

    public function __construct(Drain $drain, Outbox $outbox, ?string $name = null)
    {
        $this->drain = $drain;
        $this->outbox = $outbox;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('nitrosearch:drain')
            ->setDescription('Send queued catalogue changes to NitroSearch')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Assemble batches but send nothing')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Enqueue the whole catalogue first (full walk)');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('full')) {
            $queued = $this->outbox->enqueueAll();
            $output->writeln('  full walk queued : ' . $queued . ' rows');
        }

        $output->writeln('  pending before   : ' . $this->outbox->pendingCount());

        $result = $this->drain->run((bool) $input->getOption('dry-run'));

        $output->writeln('  batches          : ' . $result['batches']);
        $output->writeln('  items            : ' . $result['items']);
        $output->writeln('  pending after    : ' . $this->outbox->pendingCount());

        if ($result['reason'] !== '') {
            $output->writeln('  <comment>note             : ' . $result['reason'] . '</comment>');
        }

        if ($result['failed']) {
            $output->writeln('  <error>the run stopped on a failure; rows were returned to the queue</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
