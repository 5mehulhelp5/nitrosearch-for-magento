<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Mview\View\StateInterface;
use Magento\Framework\Mview\ViewInterfaceFactory;
use NitroSearch\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento nitrosearch:status` — the answer to "is this actually working?"
 *
 * THIS COMMAND EXISTS BECAUSE THE MODULE'S WORST FAILURE IS SILENT. If
 * `subscribe()` never ran — the database user lacks the TRIGGER privilege, a
 * merchant restored a database without triggers, someone ran `unsubscribe` — then
 * the module is installed, enabled, configured, connected, shows no error
 * anywhere, and syncs nothing. Every screen says it is fine.
 *
 * So this reports the things that distinguish "working" from "looks like working",
 * and nothing else:
 *
 *   subscription mode   enabled means triggers exist; anything else means they do not
 *   version_id          our cursor. Stuck while the changelog grows = the cron is
 *                       not running our view
 *   changelog rows      changes Magento has recorded for us
 *   outbox pending      changes we have accepted and not yet sent
 *
 * A merchant reading "subscribed: NO" has a fixable problem. A merchant reading a
 * green admin screen with a stale index has an unfalsifiable one.
 */
class Status extends Command
{
    private ViewInterfaceFactory $viewFactory;
    private ResourceConnection $resource;
    private Settings $settings;

    public function __construct(
        ViewInterfaceFactory $viewFactory,
        ResourceConnection $resource,
        Settings $settings,
        ?string $name = null
    ) {
        $this->viewFactory = $viewFactory;
        $this->resource = $resource;
        $this->settings = $settings;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('nitrosearch:status')
            ->setDescription('Report NitroSearch connection and change-detection state');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = $this->resource->getConnection();

        $view = $this->viewFactory->create()->load('nitrosearch_product');
        $registered = $view->getId() !== null;
        $mode = $registered ? $view->getState()->getMode() : 'not registered';
        $subscribed = $registered && $mode === StateInterface::MODE_ENABLED;

        $output->writeln('<info>Connection</info>');
        $output->writeln('  connected     : ' . ($this->settings->isConnected() ? 'yes' : 'no'));
        $output->writeln('  verified      : ' . ($this->settings->get('VERIFIED') ? 'yes' : 'no'));
        $output->writeln('  api           : ' . $this->settings->apiUrl());
        $output->writeln('');

        $output->writeln('<info>Change detection</info>');
        $output->writeln('  view          : nitrosearch_product (' . ($registered ? 'registered' : 'NOT REGISTERED') . ')');
        $output->writeln('  subscribed    : ' . ($subscribed ? 'yes' : '<error>NO — triggers are not installed, nothing will sync</error>'));
        $output->writeln('  mode          : ' . $mode);

        if ($registered) {
            $output->writeln('  version_id    : ' . (string) $view->getState()->getVersionId());
        }

        $output->writeln('  changelog rows: ' . $this->countTable($connection, 'nitrosearch_product_cl'));
        $output->writeln('  outbox pending: ' . $this->countTable(
            $connection,
            $this->resource->getTableName('nitrosearch_outbox'),
            "status = 'pending'"
        ));

        if (!$subscribed) {
            $output->writeln('');
            $output->writeln('Run <comment>bin/magento nitrosearch:subscribe</comment> to create the triggers.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Counts, but never throws. A missing changelog table is a REAL and reportable
     * state — it is exactly what an unsubscribed install looks like — so it must
     * read as "-" rather than as a stack trace that hides the three lines above it.
     */
    private function countTable($connection, string $table, ?string $where = null): string
    {
        try {
            $select = $connection->select()->from($table, ['COUNT(*)']);

            if ($where !== null) {
                $select->where($where);
            }

            return (string) (int) $connection->fetchOne($select);
        } catch (\Throwable $e) {
            return '- (table absent)';
        }
    }
}
