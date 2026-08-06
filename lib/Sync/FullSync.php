<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

namespace NitroSearch\Sync;

use NitroSearch\Settings;

/**
 * The first walk of the catalogue, and every re-walk after it.
 *
 * IT ENQUEUES; IT DOES NOT SEND. The walk's only job is to put every product into
 * the outbox, which the drain then empties at its own pace. Keeping them separate
 * is what makes a 40,000-product catalogue survivable in an environment with no job
 * queue: the walk is a cheap `SELECT product_id` that resumes from a cursor, and the
 * expensive part — serialising and sending — is already bounded by the drain's time
 * budget and the service's rate limits.
 *
 * RESUMABLE BY CONSTRUCTION. The cursor is the last product id enqueued, held in
 * settings, and the walk always asks for ids greater than it in ascending order. A
 * tick that dies halfway loses nothing but the work of one chunk, and a merchant who
 * never sets up cron still finishes eventually through the page-load fallback.
 *
 * ⚠ IT IS NOT A RECONCILER. A product deleted directly in the database while the
 * walk is stopped is not noticed here, because a walk only ever sees what exists.
 * That is the service's resync request to ask for, not something to reinvent.
 */
final class FullSync
{
    /** Ids per tick. Cheap enough that the walk is never the slow part. */
    const CHUNK = 500;

    /** @var object */
    private $db;

    /** @var Settings */
    private $settings;

    /** @var Schema */
    private $schema;

    /** @var Outbox */
    private $outbox;

    public function __construct($db, Settings $settings, Schema $schema)
    {
        $this->db = $db;
        $this->settings = $settings;
        $this->schema = $schema;
        $this->outbox = new Outbox($db);
    }

    /**
     * Begin a walk. Safe to call again — it restarts from the beginning.
     */
    public function start()
    {
        $this->settings->update(array(
            'FULLSYNC_ACTIVE' => true,
            'FULLSYNC_CURSOR' => 0,
            'FULLSYNC_TOTAL' => $this->countProducts(),
            'FULLSYNC_STARTED' => gmdate('c'),
            'FULLSYNC_DONE' => '',
        ));
    }

    /**
     * @return bool
     */
    public function isActive()
    {
        return (bool) $this->settings->get('FULLSYNC_ACTIVE');
    }

    /**
     * Enqueue the next chunk of products.
     *
     * @return array{active: bool, cursor: int, enqueued: int, total: int}
     */
    public function step()
    {
        if (!$this->isActive()) {
            return array('active' => false, 'cursor' => 0, 'enqueued' => 0, 'total' => 0);
        }

        $cursor = (int) $this->settings->get('FULLSYNC_CURSOR');

        // EXCLUDES OPENCART 4'S VARIANT ROWS. A variant is its own `product` row with
        // `master_id` set, and walking it as a product would send every variation as a
        // separate top-level item — filling a shopper's results with near-duplicates
        // and multiplying what the merchant is charged, since one product is one
        // indexed object and one unit of their plan. The serializer folds them into
        // their parent instead.
        $where = "`product_id` > " . $cursor;
        if ($this->schema->hasVariantProducts()) {
            $where .= " AND (`master_id` IS NULL OR `master_id` = 0)";
        }

        $result = $this->db->query(
            "SELECT `product_id` FROM `" . DB_PREFIX . "product` "
            . "WHERE " . $where . " ORDER BY `product_id` ASC LIMIT " . self::CHUNK
        );

        $enqueued = 0;
        foreach ($result->rows as $row) {
            $this->outbox->record('product', (int) $row['product_id']);
            $cursor = (int) $row['product_id'];
            $enqueued++;
        }

        if ($enqueued === 0) {
            $this->settings->update(array(
                'FULLSYNC_ACTIVE' => false,
                'FULLSYNC_DONE' => gmdate('c'),
            ));

            return array('active' => false, 'cursor' => $cursor, 'enqueued' => 0, 'total' => (int) $this->settings->get('FULLSYNC_TOTAL'));
        }

        $this->settings->update(array('FULLSYNC_CURSOR' => $cursor));

        return array(
            'active' => true,
            'cursor' => $cursor,
            'enqueued' => $enqueued,
            'total' => (int) $this->settings->get('FULLSYNC_TOTAL'),
        );
    }

    /**
     * Resume a walk that stopped without finishing.
     *
     * A walk is only ever advanced by a request, so one that stops — cron removed,
     * shop idle, a tick that died — simply sits. Calling this from the drain's entry
     * point means the next request of any kind picks it up, rather than the merchant
     * having to notice and press something.
     *
     * @return array{active: bool, cursor: int, enqueued: int, total: int}
     */
    public function resumeIfStalled()
    {
        if (!$this->isActive()) {
            return array('active' => false, 'cursor' => 0, 'enqueued' => 0, 'total' => 0);
        }

        return $this->step();
    }

    /**
     * @return int
     */
    private function countProducts()
    {
        $where = '1';
        if ($this->schema->hasVariantProducts()) {
            $where = "(`master_id` IS NULL OR `master_id` = 0)";
        }

        $result = $this->db->query("SELECT COUNT(*) AS `n` FROM `" . DB_PREFIX . "product` WHERE " . $where);

        return isset($result->row['n']) ? (int) $result->row['n'] : 0;
    }
}
