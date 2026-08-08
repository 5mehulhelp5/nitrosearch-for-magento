<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Console\Command;

use NitroSearch\Search\Model\Subscription;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento nitrosearch:subscribe` — create the database triggers.
 *
 * NO LONGER THE MANUAL STEP IT WAS. Connecting a store subscribes it, and every
 * `setup:upgrade` re-asserts the subscription for a store that is connected — so a
 * merchant should never need to type this. It stays because the invariant can still
 * be broken from outside the module: a database restored from a dump taken before
 * connection, a migration between hosts that did not carry triggers, or an
 * `unsubscribe` run to debug something and never undone.
 *
 * The failure it repairs is the module's worst one, and it is completely silent.
 * `View::update()` returns early unless the view is enabled, so a store with no
 * triggers is installed, enabled, connected, error-free — and syncing nothing.
 * `nitrosearch:status` reports it; this fixes it.
 *
 * TWO HOSTING CAUSES PRODUCE EXACTLY THAT STATE, and both are reported here rather
 * than left as a stack trace: the database user needs the TRIGGER privilege, which
 * managed MySQL sometimes withholds, and some managed MySQL additionally needs
 * `log_bin_trust_function_creators=1`.
 */
class Subscribe extends Command
{
    private Subscription $subscription;

    public function __construct(Subscription $subscription, ?string $name = null)
    {
        $this->subscription = $subscription;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('nitrosearch:subscribe')
            ->setDescription('Create the database triggers that feed the NitroSearch changelog');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->subscription->isSubscribed()) {
            $output->writeln('<info>Already subscribed.</info> Triggers are in place.');

            return Command::SUCCESS;
        }

        $error = $this->subscription->ensure();

        if ($error !== '') {
            $output->writeln('<error>Could not create triggers: ' . $error . '</error>');
            $output->writeln('');
            $output->writeln('Two hosting causes produce this, and both look like a module bug:');
            $output->writeln('  - the database user lacks the TRIGGER privilege');
            $output->writeln('  - the server needs log_bin_trust_function_creators=1');

            return Command::FAILURE;
        }

        $output->writeln('<info>Subscribed.</info> Catalogue changes now reach the NitroSearch changelog.');

        return Command::SUCCESS;
    }
}
