<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Console\Command;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
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
    /** Magento's own value for "the full page cache is Varnish or another proxy". */
    private const FPC_APPLICATION_VARNISH = 2;

    private ViewInterfaceFactory $viewFactory;
    private ResourceConnection $resource;
    private Settings $settings;
    private DeploymentConfig $deploymentConfig;
    private ScopeConfigInterface $scopeConfig;

    public function __construct(
        ViewInterfaceFactory $viewFactory,
        ResourceConnection $resource,
        Settings $settings,
        DeploymentConfig $deploymentConfig,
        ScopeConfigInterface $scopeConfig,
        ?string $name = null
    ) {
        $this->viewFactory = $viewFactory;
        $this->resource = $resource;
        $this->settings = $settings;
        $this->deploymentConfig = $deploymentConfig;
        $this->scopeConfig = $scopeConfig;
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

        // WHAT THE MODULE ACTUALLY SEES, which is not the same question as what is
        // in the database. `bin/magento config:show` validates against system.xml and
        // answers "path doesn't exist" for any path not declared as a FIELD there —
        // including every credential — so it cannot be used to answer "did my
        // credentials load?". This reads through the module's own settings layer,
        // which is the only thing whose answer matters.
        //
        // KEY NAMES ONLY, NEVER VALUES. A merchant pastes this output into a support
        // ticket.
        $loaded = array_keys($this->settings->all());
        sort($loaded);

        $output->writeln('<info>Settings the module can read</info>');
        $output->writeln('  count : ' . count($loaded));
        $output->writeln('  keys  : ' . ($loaded === [] ? '<error>NONE — the module cannot read its own configuration</error>' : implode(', ', $loaded)));
        $output->writeln('');

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

        $output->writeln('');
        $this->reportCachePosture($output);

        if (!$subscribed) {
            $output->writeln('');
            $output->writeln('Run <comment>bin/magento nitrosearch:subscribe</comment> to create the triggers.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }


    /**
     * Whether a key rotation can actually reach the cache serving the storefront.
     *
     * WHY THIS IS IN THE STATUS COMMAND AT ALL. `Cron\Clocks` renews the scoped search
     * key every 86,400s and `Model\CacheTag` cleans the tag on every page carrying it —
     * the built-in FPC directly, and an external cache through Magento's own
     * `PurgeCache`, which sends an HTTP PURGE.
     *
     * **`PurgeCache` sends that request to `http_cache_hosts` in `app/etc/env.php`,
     * and to nothing else.** With Varnish in front of a store and that key absent,
     * Magento purges its own web node, Varnish never hears about it, and the edge
     * serves a page holding a dead search key until its TTL expires. MEASURED on a
     * real Varnish: the rotation reported "cached pages invalidated", the origin
     * served the new key, and the edge kept serving the old one on a HIT.
     *
     * Nothing errors, nothing logs, and the failure looks exactly like a working
     * store to everyone except a shopper, whose search box quietly returns nothing.
     * So it is reported here — where a merchant or a support ticket can see it —
     * rather than left to be discovered from the outside.
     */
    private function reportCachePosture(OutputInterface $output): void
    {
        // READ THROUGH ScopeConfig, NOT core_config_data. The first version of this
        // method queried the table directly and reported "Magento built-in" on a store
        // configured for Varnish — because `config:set --lock-env` writes the value
        // into `app/etc/env.php` and never touches the database. Same shape as the
        // widget base URL that was pinned in env.php while a DB read saw nothing; a
        // status line that is confidently wrong is worse than no line.
        $application = (int) $this->scopeConfig->getValue('system/full_page_cache/caching_application');
        $usesExternal = $application === self::FPC_APPLICATION_VARNISH;

        $hosts = [];

        try {
            $hosts = (array) ($this->deploymentConfig->get('http_cache_hosts') ?? []);
        } catch (\Throwable $e) {
            $hosts = [];
        }

        $output->writeln('<info>Page cache</info>');
        $output->writeln('  application   : ' . ($usesExternal ? 'Varnish or another proxy' : 'Magento built-in'));

        if (!$usesExternal) {
            $output->writeln('  key rotation  : cleans the built-in cache directly');

            return;
        }

        if ($hosts === []) {
            $output->writeln('  purge hosts   : <error>NONE — set http_cache_hosts in app/etc/env.php</error>');
            $output->writeln('  key rotation  : <error>CANNOT REACH THE EDGE. Renewing the search key will re-render the origin and leave the proxy serving a dead key until its TTL expires. Storefront search stops silently.</error>');

            return;
        }

        $described = [];
        foreach ($hosts as $host) {
            $described[] = is_array($host)
                ? ((string) ($host['host'] ?? '?') . ':' . (string) ($host['port'] ?? 80))
                : (string) $host;
        }

        $output->writeln('  purge hosts   : ' . implode(', ', $described));
        $output->writeln('  key rotation  : purges the built-in cache AND those hosts');
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
