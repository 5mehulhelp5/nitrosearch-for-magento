<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Cron;

use NitroSearch\Search\Model\Drain;

/**
 * The 300-second drain, on the merchant's own cron.
 *
 * Five minutes is the same poll interval the other three connectors run, chosen
 * there and kept here so a merchant moving between platforms sees the same freshness.
 * The work itself is time-boxed inside {@see Drain}, so a slow API makes a run do
 * less rather than run long.
 */
class DrainJob
{
    private Drain $drain;

    public function __construct(Drain $drain)
    {
        $this->drain = $drain;
    }

    public function execute(): void
    {
        // Drain::run() never throws by design — a search API that is briefly
        // unreachable must not put a fatal in a merchant's cron log.
        $this->drain->run();
    }
}
