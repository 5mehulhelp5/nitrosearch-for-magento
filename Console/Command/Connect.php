<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Console\Command;

use NitroSearch\Search\Model\ConnectService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento nitrosearch:connect` — connect without opening the admin.
 *
 * THE OTHER THREE CONNECTORS HAVE ADMIN BUTTONS AND NOTHING ELSE, because their
 * platforms have no usable CLI. Magento does, and a Magento merchant is far more
 * likely than a WooCommerce one to be deploying from a pipeline where clicking a
 * button in a browser is the awkward step — a build that runs `setup:upgrade` and
 * `cache:flush` unattended should be able to connect the same way.
 *
 * `--site-url` EXISTS FOR A REAL SHAPE, NOT FOR CONVENIENCE. The module normally
 * reports the indexed store view's own base URL, because that is what NitroSearch will
 * fetch to prove the merchant controls it. But a store behind a reverse proxy, a
 * headless install, or a staging host whose internal and public names differ has a
 * base URL that is not the address the service can reach. Passing it explicitly is the
 * honest fix; the alternative is a merchant whose verification fails for a reason no
 * screen can explain.
 *
 * It is persisted like any other, so the drain and both clocks sign with the same
 * value — a site URL is a signing input, and one that differs between the connect and
 * everything after it produces a 401 that looks like a bad secret.
 */
class Connect extends Command
{
    private ConnectService $connectService;

    public function __construct(ConnectService $connectService, ?string $name = null)
    {
        $this->connectService = $connectService;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('nitrosearch:connect')
            ->setDescription('Connect this store to NitroSearch')
            ->addOption(
                'verify',
                null,
                InputOption::VALUE_NONE,
                'Re-ask for verification on an already-connected store, and pick up the search key'
            )
            ->addOption(
                'site-url',
                null,
                InputOption::VALUE_REQUIRED,
                'The URL NitroSearch should fetch to verify this store, if it differs from the base URL'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // RE-ASKING IS A DIFFERENT ACTION FROM CONNECTING, and conflating them is the
        // mistake worth avoiding: a merchant whose hosting was not reachable at connect
        // time must be able to retry the VERIFICATION without throwing away working
        // credentials and taking a new install id — which is what a second connect
        // would do, and which the service correctly refuses with a 409.
        if ($input->getOption('verify')) {
            $r = $this->connectService->refresh();

            if ($r['reason'] === 'not_connected') {
                $output->writeln('<error>This store is not connected yet.</error>');

                return Command::FAILURE;
            }

            if ($r['verified']) {
                $output->writeln('<info>Verified.</info> Storefront search is live.');

                return Command::SUCCESS;
            }

            $output->writeln('<comment>Still not verified'
                . ($r['reason'] !== '' ? ' (' . $r['reason'] . ')' : '')
                . '.</comment> We fetch a route on your storefront to prove you control it,');
            $output->writeln('so it has to be reachable from the public internet.');

            return Command::SUCCESS;
        }

        $override = (string) ($input->getOption('site-url') ?? '');

        $result = $this->connectService->connect($override !== '' ? $override : null);

        if (!$result['ok']) {
            $output->writeln('<error>Could not connect: ' . $result['error'] . '</error>');

            return Command::FAILURE;
        }

        if (($result['subscribeError'] ?? '') !== '') {
            $output->writeln('<comment>Connected, but the change-detection triggers could not be '
                . 'created: ' . $result['subscribeError'] . '</comment>');
            $output->writeln('<comment>The store will not notice catalogue changes until that is fixed.</comment>');
        }

        if ($result['verified']) {
            $output->writeln('<info>Connected and verified.</info> The catalogue will sync on the next cron run.');
        } else {
            $output->writeln('<comment>Connected, but not yet verified'
                . ($result['reason'] !== '' ? ' (' . $result['reason'] . ')' : '')
                . '.</comment>');
            $output->writeln('This is normal on a local, staging or password-protected site — we have to');
            $output->writeln('reach your storefront from the outside. Run <comment>nitrosearch:clocks</comment> once it is.');
        }

        return Command::SUCCESS;
    }
}
