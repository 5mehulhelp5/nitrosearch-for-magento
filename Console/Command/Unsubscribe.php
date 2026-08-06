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
 * `bin/magento nitrosearch:unsubscribe` — drop the database triggers.
 *
 * **THE SHARPEST BUG IN THIS MODULE'S DESIGN IS UNINSTALL, and this command is the
 * fix.** `composer remove` followed by `setup:upgrade` drops `nitrosearch_outbox`
 * through declarative schema — but **Mview triggers are not schema**. Without this,
 * a merchant who uninstalls is left with database triggers writing into a changelog
 * table that no longer exists, and every catalogue write on their store starts
 * failing. The README tells them to run it first; the disable path calls it too.
 *
 */
class Unsubscribe extends Command
{
    private ViewInterfaceFactory $viewFactory;

    public function __construct(ViewInterfaceFactory $viewFactory, ?string $name = null)
    {
        $this->viewFactory = $viewFactory;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('nitrosearch:unsubscribe')
            ->setDescription('Remove the NitroSearch database triggers — run this BEFORE uninstalling');

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

        if ($view->getState()->getMode() !== \Magento\Framework\Mview\View\StateInterface::MODE_ENABLED) {
            $output->writeln('<info>Not subscribed.</info> Nothing to remove.');

            return Command::SUCCESS;
        }

        try {
            $view->unsubscribe();
        } catch (\Throwable $e) {
            $output->writeln('<error>Could not drop triggers: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Unsubscribed.</info> Triggers removed.');

        return Command::SUCCESS;
    }
}
