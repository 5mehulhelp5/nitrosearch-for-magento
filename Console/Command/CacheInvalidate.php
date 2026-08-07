<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Console\Command;

use NitroSearch\Search\Model\CacheTag;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento nitrosearch:cache-invalidate` — re-render the pages carrying the blob.
 *
 * The support command for "my search stopped working and the admin says connected".
 * The usual cause is a cached page holding a scoped key that has since rotated, and
 * the usual instinct is `cache:flush`, which on a busy store is a thundering herd
 * against the merchant's own PHP workers. This cleans one tag.
 *
 * It also exists so the mechanism can be TESTED. The failure it prevents takes 24
 * hours to occur naturally, so without a way to trigger the invalidation on demand
 * the whole cache-tag design would be unfalsifiable — and docs' own note is that this
 * mechanism has no prior art in the three shipped connectors and therefore no wrong
 * implementation anywhere to check a right one against.
 */
class CacheInvalidate extends Command
{
    private CacheTag $cacheTag;

    public function __construct(CacheTag $cacheTag, ?string $name = null)
    {
        $this->cacheTag = $cacheTag;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('nitrosearch:cache-invalidate')
            ->setDescription('Re-render cached pages carrying the NitroSearch config blob');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cacheTag->invalidate();

        $output->writeln('<info>Invalidated.</info> Pages carrying the NitroSearch config will re-render.');

        return Command::SUCCESS;
    }
}
