<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Console\Command;

use Magento\Framework\Mview\ViewInterface;
use Magento\Framework\Mview\ViewInterfaceFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento nitrosearch:subscribe` — create the database triggers.
 *
 * THE ONE THING THAT IS NOT AUTOMATIC, and the reason it is not is a deliberate
 * trade-off recorded in `etc/mview.xml`: this module declares an mview view but no
 * INDEXER, so `bin/magento indexer:set-mode schedule` — which is how a merchant
 * would normally turn a view's triggers on — cannot reach us. Declaring an indexer
 * to get that for free would put a row in Index Management that a merchant could
 * set to "Update on Save", silently stopping the subscription with no error
 * anywhere.
 *
 * `View::subscribe()` is what actually issues the `CREATE TRIGGER` statements, and
 * `View::update()` returns early unless the view is enabled. So a module whose
 * subscription never ran looks completely healthy and syncs nothing — which is why
 * `nitrosearch:status` reports this state explicitly rather than leaving it to be
 * inferred.
 *
 * TWO HOSTING CAUSES PRODUCE EXACTLY THAT FAILURE, and both are reported here
 * rather than left as a stack trace: the database user needs the TRIGGER privilege,
 * which managed MySQL sometimes withholds, and some managed MySQL additionally
 * needs `log_bin_trust_function_creators=1`. Both produce a clean-looking install
 * with no triggers.
 */
class Subscribe extends Command
{
    private ViewInterfaceFactory $viewFactory;

    public function __construct(ViewInterfaceFactory $viewFactory, ?string $name = null)
    {
        $this->viewFactory = $viewFactory;
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
        /** @var ViewInterface $view */
        $view = $this->viewFactory->create()->load('nitrosearch_product');

        if ($view->getId() === null) {
            $output->writeln('<error>View nitrosearch_product is not registered. Run setup:upgrade first.</error>');

            return Command::FAILURE;
        }

        if ($view->getState()->getMode() === \Magento\Framework\Mview\View\StateInterface::MODE_ENABLED) {
            $output->writeln('<info>Already subscribed.</info> Triggers are in place.');

            return Command::SUCCESS;
        }

        try {
            $view->subscribe();
        } catch (\Throwable $e) {
            $output->writeln('<error>Could not create triggers: ' . $e->getMessage() . '</error>');
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
