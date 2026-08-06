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

use NitroSearch\AdapterKit\Batch;
use NitroSearch\Api\Client;
use NitroSearch\Settings;

/**
 * Empties the outbox, politely.
 *
 * NEITHER MAJOR HAS A JOB QUEUE THIS CAN USE. OpenCart 3 has nothing at all;
 * OpenCart 4 has a cron table rather than a worker, and it only runs when someone
 * hits its endpoint. So the drain runs in a request — a merchant's cron hitting a
 * token-gated url, with a small page-load fallback for shops that never set one up
 * — and everything here follows from that:
 *
 *  - **It is time-boxed, not size-boxed.** A budget in seconds is the only bound
 *    that means anything when the same code runs behind a 30-second page load and a
 *    5-minute cron. A batch count would be tuned for one and wrong for the other.
 *  - **It stops on the first failure rather than pressing on.** A failing service is
 *    usually failing for every batch, and continuing turns one merchant's outage
 *    into a retry storm against it. The rows go back to `pending` and the next tick
 *    tries again.
 *  - **It never throws.** A fatal here would surface as a 500 on a shopper's page
 *    when the fallback path is what triggered it.
 */
final class Drain
{
    /** The wire refuses more than this per batch, so the kit does too. */
    const BATCH_SIZE = 100;

    /** @var object */
    private $db;

    /** @var Settings */
    private $settings;

    /** @var Client */
    private $client;

    /** @var ProductSerializer */
    private $serializer;

    /** @var Outbox */
    private $outbox;

    public function __construct($db, Settings $settings, Client $client, ProductSerializer $serializer)
    {
        $this->db = $db;
        $this->settings = $settings;
        $this->client = $client;
        $this->serializer = $serializer;
        $this->outbox = new Outbox($db);
    }

    /**
     * Send what is queued, within a time budget.
     *
     * @param int $budgetSeconds
     *
     * @return array{batches: int, items: int, stopped: string, pending: int}
     */
    public function run($budgetSeconds = 20)
    {
        $result = array('batches' => 0, 'items' => 0, 'stopped' => 'empty', 'pending' => 0);

        if (!$this->settings->isConnected()) {
            $result['stopped'] = 'not_connected';

            return $result;
        }

        // Recover anything a previous drain claimed and never finished. A drain dies
        // for reasons this module cannot catch — a PHP timeout mid-request, a killed
        // cron, a host restart — and without this those rows sit in `sending` forever
        // while every surface reports the queue as healthy.
        $this->outbox->requeueStalled();

        $deadline = microtime(true) + max(1, (int) $budgetSeconds);

        while (microtime(true) < $deadline) {
            $rows = $this->outbox->claim(self::BATCH_SIZE);

            if (empty($rows)) {
                $result['stopped'] = 'empty';
                break;
            }

            $sent = $this->sendOne($rows);

            $result['batches']++;
            $result['items'] += $sent['items'];

            if (!$sent['ok']) {
                $result['stopped'] = 'error';
                break;
            }

            $result['stopped'] = 'budget';
        }

        $result['pending'] = $this->outbox->pendingCount();

        return $result;
    }

    /**
     * Serialise and send one claimed set of rows.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array{ok: bool, items: int}
     */
    private function sendOne(array $rows)
    {
        $batch = new Batch();
        $ids = array();

        // Rows whose object has vanished between being queued and being drained.
        // They are DROPPED rather than released: re-queueing them would spin forever
        // on something that will never serialise, and a delete was already sent for
        // anything actually removed through the shop.
        $vanished = array();

        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
            $objectId = (int) $row['object_id'];

            try {
                if ((string) $row['op'] === 'delete') {
                    $item = $this->serializer->tombstone($objectId);
                } else {
                    $item = $this->serializer->serialize($objectId);
                }
            } catch (\Exception $e) {
                // One unserialisable product must not stop the other ninety-nine.
                // It is recorded and skipped rather than retried forever.
                $this->note('serialise failed for ' . $row['object_type'] . ' ' . $objectId . ': ' . $e->getMessage());
                $vanished[] = (int) $row['id'];
                continue;
            } catch (\Throwable $e) {
                $this->note('serialise failed for ' . $row['object_type'] . ' ' . $objectId . ': ' . $e->getMessage());
                $vanished[] = (int) $row['id'];
                continue;
            }

            if ($item === null) {
                // Gone from the catalogue with no delete recorded — a direct database
                // edit, a plugin that bypasses events. Send a tombstone so the index
                // does not keep something the shop no longer has.
                $item = $this->serializer->tombstone($objectId);
            }

            $batch->add($item);
        }

        if ($batch->isEmpty()) {
            $this->outbox->forget($ids);

            return array('ok' => true, 'items' => 0);
        }

        $response = $this->client->ingestBatch($batch);

        if (empty($response['ok'])) {
            // Everything goes back, including the rows that serialised fine: the
            // batch was rejected as a whole and we do not know which items the
            // service did or did not record.
            $this->outbox->release(array_values(array_diff($ids, $vanished)));
            $this->outbox->forget($vanished);
            $this->note(isset($response['error']) ? (string) $response['error'] : 'ingest failed');

            return array('ok' => false, 'items' => 0);
        }

        $this->outbox->forget($ids);
        $this->settings->update(array('LAST_SYNC' => gmdate('c'), 'LAST_ERROR' => ''));

        return array('ok' => true, 'items' => $batch->count());
    }

    /**
     * Record the most recent failure, bounded, for the Configure screen.
     *
     * ONE SLOT, NOT A LOG. A merchant needs to know that something is wrong and
     * roughly what; a growing log in a settings row is a table that nothing prunes.
     *
     * @param string $message
     */
    private function note($message)
    {
        $this->settings->update(array('LAST_ERROR' => substr((string) $message, 0, 500)));
    }
}
