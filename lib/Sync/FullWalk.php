<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

namespace NitroSearch\Sync;

/**
 * What {@see ResyncCheck} needs in order to start a full walk.
 *
 * TWO METHODS, WHICH IS EXACTLY WHAT IT CALLS. `ResyncCheck` handles a resync
 * instruction from the service by asking "is a walk already running?" and, if not,
 * starting one. It has never needed anything else, and adding a richer port would be
 * guessing at a second implementation rather than extracting one.
 *
 * THE PORT EXISTS BECAUSE THE WALK IS PLATFORM-SHAPED AND THE CLOCKS ARE NOT.
 * `ResyncCheck` carries reasoning that took this project real incidents to arrive at
 * — the refresh clock stamped before the eligibility test, backfill not being
 * renewal, a malformed 200 never overwriting a stored key — and none of that is
 * specific to any platform, so it is shared byte-identically. Enumerating a
 * catalogue, by contrast, is entirely specific: OpenCart's implementation pages
 * through its own tables with a cursor, and Magento's is a single `INSERT … SELECT`
 * because it has a query builder and a real outbox table. Both satisfy this.
 */
interface FullWalk
{
    /**
     * Whether a walk is already in progress.
     *
     * Load-bearing: a resync instruction that arrives while a walk is running must
     * not restart it. The service repeats the instruction until it is acknowledged,
     * so without this check a large catalogue would restart every five minutes and
     * never finish.
     *
     * @return bool
     */
    public function isActive();

    /** Begin a walk of the whole catalogue. */
    public function start();
}
