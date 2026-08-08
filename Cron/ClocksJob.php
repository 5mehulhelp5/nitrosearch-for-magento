<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Cron;

use NitroSearch\Search\Model\Clocks;

/**
 * The heartbeat, on the merchant's own cron.
 *
 * RUNS EVERY FIVE MINUTES AND IS NOT GATED ON THERE BEING SYNC WORK. That is the
 * single most important line in this file. An empty outbox is the steady state of a
 * healthy catalogue — and a store with a healthy, unchanging catalogue is exactly the
 * store whose search key quietly expires while nothing anywhere reports a problem.
 * Every connector on this project that tied its heartbeat to having something to send
 * had to have it untied again.
 *
 * The two clocks decide between themselves which of them is actually due; this only
 * has to fire often enough for the shorter of the two.
 */
class ClocksJob
{
    private Clocks $clocks;

    public function __construct(Clocks $clocks)
    {
        $this->clocks = $clocks;
    }

    public function execute(): void
    {
        $this->clocks->run();
    }
}
