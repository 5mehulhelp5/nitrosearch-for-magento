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

/**
 * The local dirty queue.
 *
 * Events write coalesced rows here — one row per object, last write wins — doing
 * ZERO http and ZERO payload building. That is what keeps a product save, a stock
 * movement and a checkout fast, and it is what lets the shop keep recording
 * changes while NitroSearch is unreachable.
 *
 * WHY A QUEUE AT ALL, RATHER THAN SENDING ON SAVE. Two reasons, and the second is
 * the one that matters. A synchronous send puts a network round trip inside the
 * merchant's own save path, so an outage becomes their outage. And a bulk action —
 * a CSV import, a supplier price update, a category reassignment — would fire one
 * request per row; coalescing turns ten thousand writes to the same hundred
 * products into a hundred rows.
 *
 * OPENCART 3 HAS NO JOB QUEUE AT ALL and OpenCart 4's is a cron table rather than a
 * worker, so neither major can be asked to run this in the background. The queue is
 * drained by a merchant's cron hitting a token-gated endpoint, with a small
 * page-load fallback for shops that never set one up. That is the same shape
 * PrestaShop needed, for the same reason.
 */
final class Outbox
{
    /** @var object OpenCart's DB library — query()/escape(), same in both majors */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @return string the fully-prefixed table name
     */
    public static function table()
    {
        return DB_PREFIX . 'nitrosearch_queue';
    }

    /**
     * The CREATE TABLE this module installs.
     *
     * `InnoDB` explicitly, NOT the engine OpenCart's own tables use. OpenCart 3's
     * schema still creates MyISAM tables, which have no transactions and lock the
     * whole table on write — on a shop importing a catalogue that turns this queue
     * into a serialisation point for every product save. The one place it matters is
     * the one place this module writes on the merchant's critical path.
     *
     * @return string
     */
    public static function schema()
    {
        return 'CREATE TABLE IF NOT EXISTS `' . self::table() . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `object_type` VARCHAR(20) NOT NULL,
            `object_id` INT UNSIGNED NOT NULL,
            `op` VARCHAR(10) NOT NULL,
            `version` BIGINT UNSIGNED NOT NULL,
            `status` VARCHAR(10) NOT NULL DEFAULT \'pending\',
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `object` (`object_type`, `object_id`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';
    }

    /**
     * A monotonic per-write version: milliseconds since epoch.
     *
     * THIS IS THE LAST-WRITE-WINS CLOCK THE SERVICE ARBITRATES ON, so it has to
     * increase. A constant or a second-resolution timestamp means two edits inside
     * the same second are indistinguishable, and the service — which skips any item
     * whose version is not greater than the one it holds — would silently drop the
     * second one.
     *
     * @return int
     */
    private static function version()
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * Record that one object changed.
     *
     * The upsert COALESCES: a second change to the same object before the first has
     * drained updates the existing row rather than adding one, and returns it to
     * `pending` so an edit landing mid-drain is not lost.
     *
     * @param string $objectType 'product' or 'page'
     * @param int    $objectId
     * @param string $op         'upsert' or 'delete'
     */
    public function record($objectType, $objectId, $op = 'upsert')
    {
        $objectId = (int) $objectId;
        if ($objectId <= 0) {
            return;
        }

        $this->db->query(
            "INSERT INTO `" . self::table() . "` SET "
            . "`object_type` = '" . $this->db->escape((string) $objectType) . "', "
            . "`object_id` = " . $objectId . ", "
            . "`op` = '" . $this->db->escape((string) $op) . "', "
            . "`version` = " . self::version() . ", "
            . "`status` = 'pending', "
            . "`updated_at` = NOW() "
            . "ON DUPLICATE KEY UPDATE "
            . "`op` = VALUES(`op`), `version` = VALUES(`version`), "
            . "`status` = 'pending', `updated_at` = NOW()"
        );
    }

    /**
     * Claim up to $limit pending rows for sending.
     *
     * MARKS THEM `sending` BEFORE RETURNING, so two overlapping drains — a cron tick
     * that overruns into the next one, or a cron and a page-load fallback firing
     * together — cannot both send the same rows. A row left `sending` by a drain
     * that died is recovered by {@see requeueStalled()} rather than stranded.
     *
     * @param int $limit
     *
     * @return array<int, array<string, mixed>>
     */
    public function claim($limit = 100)
    {
        $limit = max(1, (int) $limit);

        $result = $this->db->query(
            "SELECT `id`, `object_type`, `object_id`, `op`, `version` FROM `" . self::table() . "` "
            . "WHERE `status` = 'pending' ORDER BY `id` ASC LIMIT " . $limit
        );

        $rows = $result->rows;
        if (empty($rows)) {
            return array();
        }

        $ids = array();
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
        }

        $this->db->query(
            "UPDATE `" . self::table() . "` SET `status` = 'sending', `updated_at` = NOW() "
            . "WHERE `id` IN (" . implode(',', $ids) . ")"
        );

        return $rows;
    }

    /**
     * Drop rows that were sent successfully.
     *
     * DELETED RATHER THAN MARKED DONE, and only rows still in `sending`. If an edit
     * landed while the batch was in flight, {@see record()} has already returned that
     * row to `pending` — deleting it unconditionally would discard the newer change
     * and leave the index holding what we sent instead of what the shop now says.
     *
     * @param array<int, int> $ids
     */
    public function forget(array $ids)
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return;
        }

        $this->db->query(
            "DELETE FROM `" . self::table() . "` WHERE `status` = 'sending' AND `id` IN (" . implode(',', $ids) . ")"
        );
    }

    /**
     * Return claimed rows to the queue after a failed send.
     *
     * @param array<int, int> $ids
     */
    public function release(array $ids)
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return;
        }

        $this->db->query(
            "UPDATE `" . self::table() . "` SET `status` = 'pending', `updated_at` = NOW() "
            . "WHERE `status` = 'sending' AND `id` IN (" . implode(',', $ids) . ")"
        );
    }

    /**
     * Recover rows stranded in `sending` by a drain that never finished.
     *
     * A drain dies for reasons this module cannot catch — a PHP timeout mid-request,
     * a killed cron, a host restart. Without this, those rows sit in `sending`
     * forever and the objects they describe silently stop syncing while every
     * surface reports the queue as healthy.
     *
     * @param int $olderThanMinutes
     *
     * @return int rows recovered
     */
    public function requeueStalled($olderThanMinutes = 15)
    {
        $minutes = max(1, (int) $olderThanMinutes);

        $this->db->query(
            "UPDATE `" . self::table() . "` SET `status` = 'pending' "
            . "WHERE `status` = 'sending' AND `updated_at` < DATE_SUB(NOW(), INTERVAL " . $minutes . " MINUTE)"
        );

        return (int) $this->db->countAffected();
    }

    /**
     * @return int
     */
    public function pendingCount()
    {
        $result = $this->db->query("SELECT COUNT(*) AS `n` FROM `" . self::table() . "` WHERE `status` = 'pending'");

        return isset($result->row['n']) ? (int) $result->row['n'] : 0;
    }

    /** Empty the queue — used by uninstall. */
    public function drop()
    {
        $this->db->query("DROP TABLE IF EXISTS `" . self::table() . "`");
    }
}
