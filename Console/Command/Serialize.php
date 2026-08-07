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
use Magento\Store\Model\StoreManagerInterface;
use NitroSearch\Search\Model\Outbox;
use NitroSearch\Search\Model\ProductSerializer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento nitrosearch:serialize` — show what would be sent, and send nothing.
 *
 * THIS EXISTS BECAUSE THE EXPENSIVE MISTAKES ON THIS PROJECT WERE ALL FOUND BY
 * RUNNING SOMETHING, and until now the only way to see a serialized item was to
 * connect a store and watch what arrived. A dry run against a real catalogue makes
 * the two decisions in `ProductSerializer` inspectable before any of it is live:
 * which products are indexable (and therefore billable), and what the wire item
 * actually contains.
 *
 * It also answers the quota question directly. `--count` reports how many of a
 * merchant's catalogue rows are billable under the visibility rule, which is the
 * number they will be charged against — measurable on their own store rather than
 * estimated from ours.
 */
class Serialize extends Command
{
    private ProductSerializer $serializer;
    private Outbox $outbox;
    private StoreManagerInterface $storeManager;
    private ResourceConnection $resource;

    public function __construct(
        ProductSerializer $serializer,
        Outbox $outbox,
        StoreManagerInterface $storeManager,
        ResourceConnection $resource,
        ?string $name = null
    ) {
        $this->serializer = $serializer;
        $this->outbox = $outbox;
        $this->storeManager = $storeManager;
        $this->resource = $resource;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('nitrosearch:serialize')
            ->setDescription('Dry run: show the wire items a set of products would produce')
            ->addOption('ids', null, InputOption::VALUE_REQUIRED, 'Comma-separated product ids')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Serialize the first N products', '3')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store view id', '1')
            ->addOption('count', null, InputOption::VALUE_NONE, 'Only report how many products are billable');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->storeManager->getStore((int) $input->getOption('store'));
        $connection = $this->resource->getConnection();

        if ($input->getOption('count')) {
            $total = (int) $connection->fetchOne(
                $connection->select()->from($this->resource->getTableName('catalog_product_entity'), ['COUNT(*)'])
            );

            $allIds = $connection->fetchCol(
                $connection->select()->from($this->resource->getTableName('catalog_product_entity'), ['entity_id'])
            );

            // Serialized in slices so a large catalogue does not build one enormous
            // array purely to count it.
            $billable = 0;
            foreach (array_chunk($allIds, 500) as $chunk) {
                foreach ($this->serializer->serialize($chunk, $store, 0) as $item) {
                    if (($item['op'] ?? 'upsert') !== 'delete') {
                        $billable++;
                    }
                }
            }

            $output->writeln('  catalogue rows : ' . $total);
            $output->writeln('  <info>billable       : ' . $billable . '</info>');
            $output->writeln('  not indexed    : ' . ($total - $billable)
                . ' (disabled, not visible individually, or not assigned to this website)');

            return Command::SUCCESS;
        }

        if ($input->getOption('ids')) {
            $ids = array_map('intval', explode(',', (string) $input->getOption('ids')));
        } else {
            $ids = array_map('intval', $connection->fetchCol(
                $connection->select()
                    ->from($this->resource->getTableName('catalog_product_entity'), ['entity_id'])
                    ->order('entity_id ASC')
                    ->limit((int) $input->getOption('limit'))
            ));
        }

        $items = $this->serializer->serialize($ids, $store, (int) round(microtime(true) * 1000));

        $output->writeln(json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $output->writeln('');
        $output->writeln('  outbox pending : ' . $this->outbox->pendingCount());

        return Command::SUCCESS;
    }
}
