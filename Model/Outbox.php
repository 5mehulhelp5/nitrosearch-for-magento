<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Reading and retiring the queue the Mview action fills.
 *
 * CLAIM-THEN-SEND, NEVER SEND-THEN-DELETE. Rows are moved to `sending` before the
 * HTTP request and only deleted once the service has answered 2xx. The alternative —
 * delete on the way out — loses the batch if the request fails, and the merchant
 * never learns which products stopped being indexed. The other connectors arrived at
 * the same shape after ingest could silently drop a batch that had already been
 * pruned from the queue.
 *
 * A CRASH LEAVES ROWS IN `sending`, AND THAT IS THE DESIGN. They are recovered by
 * {@see reclaimStale()} rather than by a transaction, because the risky span is an
 * HTTP request to another host: holding a database transaction open across it would
 * pin a connection for the merchant's whole network timeout.
 */
class Outbox
{
    /**
     * How long a `sending` row may sit before it is assumed abandoned.
     *
     * Longer than any plausible request (the client's own timeout is far below this)
     * and short enough that a crashed cron recovers on its next run rather than on
     * the merchant's next complaint.
     */
    private const STALE_SECONDS = 900;

    private ResourceConnection $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Claim up to $limit pending rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function claim(int $limit): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('nitrosearch_outbox');

        $ids = $connection->fetchCol(
            $connection->select()
                ->from($table, ['id'])
                ->where('status = ?', 'pending')
                ->order('id ASC')
                ->limit($limit)
        );

        if ($ids === []) {
            return [];
        }

        $connection->update($table, ['status' => 'sending'], ['id IN (?)' => $ids]);

        return $connection->fetchAll(
            $connection->select()->from($table)->where('id IN (?)', $ids)->order('id ASC')
        );
    }

    /**
     * Read pending rows WITHOUT claiming them, for a dry run.
     *
     * THIS EXISTS BECAUSE THE OBVIOUS IMPLEMENTATION LOOPED FOREVER. The first
     * version of the dry run reused {@see claim()} and then {@see release()}d the
     * rows so that inspecting the queue would not consume it — but releasing puts
     * them straight back to `pending`, so the next `claim()` returned the same rows
     * again. A 2,040-row queue reported 6,856 batches and 685,600 items before the
     * time budget stopped it, which is how it was found.
     *
     * Reading with an id cursor and mutating nothing is both correct and more honest
     * about what a dry run is: it should not touch the queue at all, rather than
     * touch it and put it back.
     *
     * @return array<int, array<string, mixed>>
     */
    public function peek(int $limit, int $afterId = 0): array
    {
        $connection = $this->resource->getConnection();

        return $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('nitrosearch_outbox'))
                ->where('status = ?', 'pending')
                ->where('id > ?', $afterId)
                ->order('id ASC')
                ->limit($limit)
        );
    }

    /** Retire rows the service accepted. */
    public function complete(array $rowIds): void
    {
        if ($rowIds === []) {
            return;
        }

        $connection = $this->resource->getConnection();
        $connection->delete(
            $this->resource->getTableName('nitrosearch_outbox'),
            ['id IN (?)' => $rowIds]
        );
    }

    /**
     * Return rows to `pending` after a failed send, counting the attempt.
     *
     * ATTEMPTS ARE COUNTED BUT NOTHING IS EVER DROPPED FOR EXCEEDING THEM. A poison
     * row retries forever and shows up in `nitrosearch:status` as a queue that will
     * not drain, which is a visible problem a merchant can report. Silently
     * discarding it after N tries would leave a product permanently missing from
     * search with nothing anywhere saying so — and "the index is quietly wrong" is
     * the failure this whole module is arranged to avoid.
     */
    public function release(array $rowIds): void
    {
        if ($rowIds === []) {
            return;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('nitrosearch_outbox');

        $connection->update(
            $table,
            ['status' => 'pending', 'attempts' => new \Zend_Db_Expr('attempts + 1')],
            ['id IN (?)' => $rowIds]
        );
    }

    /**
     * Rescue rows abandoned mid-send by a crash, a timeout or a killed cron.
     *
     * Without this a single hard failure strands rows in `sending` permanently: they
     * are not pending, so nothing claims them, and the products they represent go
     * stale with the queue looking empty.
     */
    public function reclaimStale(): int
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('nitrosearch_outbox');

        return (int) $connection->update(
            $table,
            ['status' => 'pending'],
            [
                'status = ?' => 'sending',
                'created_at < ?' => new \Zend_Db_Expr('DATE_SUB(NOW(), INTERVAL ' . self::STALE_SECONDS . ' SECOND)'),
            ]
        );
    }

    public function pendingCount(): int
    {
        $connection = $this->resource->getConnection();

        return (int) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('nitrosearch_outbox'), ['COUNT(*)'])
                ->where('status = ?', 'pending')
        );
    }

    /**
     * Enqueue every indexable id in the catalogue — the full walk.
     *
     * THIS IS THE CORRECTNESS ARGUMENT, not the change detection. Mview catches most
     * things and provably not all of them (a full catalog-rule reindex writes through
     * a temporary table that no trigger can see), so what makes the index eventually
     * right is re-sending everything on a schedule, depending on no signal having
     * fired. The PrestaShop connector takes the same stance about its own hook list.
     *
     * INSERT … SELECT, so a 200,000-product catalogue does not become 200,000 round
     * trips or a 200,000-element PHP array. ON DUPLICATE KEY leaves anything already
     * queued alone rather than resetting its version.
     */
    public function enqueueAll(): int
    {
        $connection = $this->resource->getConnection();
        $outbox = $this->resource->getTableName('nitrosearch_outbox');
        $products = $this->resource->getTableName('catalog_product_entity');

        $version = (int) round(microtime(true) * 1000);

        $sql = sprintf(
            'INSERT INTO %s (object_type, object_id, op, version, status, store_id)'
            . ' SELECT %s, entity_id, %s, %d, %s, 0 FROM %s'
            . ' ON DUPLICATE KEY UPDATE version = VALUES(version), status = %s',
            $connection->quoteIdentifier($outbox),
            $connection->quote('product'),
            $connection->quote('upsert'),
            $version,
            $connection->quote('pending'),
            $connection->quoteIdentifier($products),
            $connection->quote('pending')
        );

        return (int) $connection->query($sql)->rowCount();
    }
}
