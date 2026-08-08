<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Model;

use NitroSearch\Settings;
use NitroSearch\Sync\FullWalk as FullWalkPort;

/**
 * Magento's full walk: one `INSERT … SELECT` into the outbox.
 *
 * NO CURSOR, WHICH IS THE WHOLE DIFFERENCE FROM THE OTHER CONNECTORS. OpenCart's
 * implementation pages through its catalogue keeping a cursor in settings, because it
 * has to enqueue and send in the same pass. Magento has a real outbox table and a
 * query builder, so the entire catalogue is enqueued in a single statement and the
 * drain — which is already time-boxed and already resumable — does the paced sending.
 * A 200,000-product store costs one query here rather than 2,000 round trips, and
 * there is no cursor to be left half-advanced by a crash.
 *
 * `FULLSYNC_ACTIVE` IS STILL TRACKED, because {@see isActive()} is what stops a
 * repeated resync instruction restarting a walk that is still draining. The service
 * repeats that instruction until it is acknowledged, so without the flag a large
 * catalogue would restart every five minutes and never finish — which is the failure
 * the port's own docblock describes.
 *
 * The flag clears when the outbox drains, checked here rather than stamped by the
 * drain: "is there anything left?" is the true condition, and a flag the drain sets
 * would go stale the moment anything else enqueued a row.
 */
class FullWalk implements FullWalkPort
{
    private Settings $settings;
    private Outbox $outbox;

    public function __construct(Settings $settings, Outbox $outbox)
    {
        $this->settings = $settings;
        $this->outbox = $outbox;
    }

    /**
     * Whether a walk is still draining.
     *
     * DERIVED, NOT STORED. The flag says a walk was started; the queue says whether
     * it has finished. Asking the queue means the answer cannot drift from reality —
     * and it self-heals a flag left set by a crash mid-walk, which would otherwise
     * make every future resync instruction a no-op and leave the store permanently
     * unable to be told to rebuild.
     */
    public function isActive(): bool
    {
        if (!$this->settings->get('FULLSYNC_ACTIVE')) {
            return false;
        }

        if ($this->outbox->pendingCount() > 0) {
            return true;
        }

        $this->settings->update([
            'FULLSYNC_ACTIVE' => false,
            'FULLSYNC_DONE' => gmdate('c'),
        ]);

        return false;
    }

    public function start(): void
    {
        $queued = $this->outbox->enqueueAll();

        $this->settings->update([
            'FULLSYNC_ACTIVE' => true,
            'FULLSYNC_TOTAL' => $queued,
            'FULLSYNC_STARTED' => gmdate('c'),
            'FULLSYNC_DONE' => '',
            // No cursor to reset — the outbox IS the cursor. Written as 0 anyway so a
            // value left by an older version cannot be read as a position in a walk
            // that no longer works that way.
            'FULLSYNC_CURSOR' => 0,
        ]);
    }
}
