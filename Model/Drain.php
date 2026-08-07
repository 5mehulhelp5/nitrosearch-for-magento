<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Model;

use Magento\Store\Model\StoreManagerInterface;
use NitroSearch\AdapterKit\Batch;
use NitroSearch\Api\Client;
use NitroSearch\Settings;

/**
 * Sends what the outbox holds.
 *
 * TIME-BOXED, NOT SIZE-BOXED — the shape every connector on this project converged
 * on. A run stops when its budget is spent, not after a fixed number of batches,
 * because the thing being protected is the merchant's cron window and the thing that
 * varies is how long their server and our API take. A size-boxed drain on a slow host
 * runs long; a time-boxed one just makes less progress and tries again.
 *
 * STOPS ON THE FIRST FAILURE, and does not try the next batch. If the API is
 * unreachable, rate-limiting, or refusing this store, every subsequent batch in the
 * same run would fail the same way — and each costs the merchant a full network
 * timeout. One failure per run is a retry; twenty is an outage on their server.
 *
 * NEVER THROWS. This runs from cron and, on the escape-hatch path, from a web
 * request. An exception escaping here is a fatal in a merchant's cron log, or a 500
 * on their storefront, for a condition — the search API being briefly unreachable —
 * that is not their problem and resolves itself. Failures are recorded and the rows
 * go back to `pending`.
 */
class Drain
{
    /** Seconds of wall clock a single run may spend. */
    private const BUDGET_SECONDS = 20;

    private Outbox $outbox;
    private ProductSerializer $serializer;
    private Settings $settings;
    private StoreManagerInterface $storeManager;

    public function __construct(
        Outbox $outbox,
        ProductSerializer $serializer,
        Settings $settings,
        StoreManagerInterface $storeManager
    ) {
        $this->outbox = $outbox;
        $this->serializer = $serializer;
        $this->settings = $settings;
        $this->storeManager = $storeManager;
    }

    /**
     * @return array{batches: int, items: int, failed: bool, reason: string}
     */
    public function run(bool $dryRun = false): array
    {
        $result = ['batches' => 0, 'items' => 0, 'failed' => false, 'reason' => ''];

        if (!$dryRun && !$this->settings->isConnected()) {
            $result['reason'] = 'not connected';

            return $result;
        }

        // Rows abandoned by a crashed run are nobody's until this puts them back.
        $this->outbox->reclaimStale();

        $store = $this->storeManager->getStore($this->indexedStoreId());
        $deadline = microtime(true) + self::BUDGET_SECONDS;

        $client = $dryRun ? null : new Client($this->settings, (string) $this->settings->get('SITE_URL'));

        // A dry run READS; a real run CLAIMS. Keeping a cursor out here is what
        // stops the dry run re-reading rows it has already counted.
        $cursor = 0;

        while (microtime(true) < $deadline) {
            $rows = $dryRun
                ? $this->outbox->peek(Batch::MAX_ITEMS, $cursor)
                : $this->outbox->claim(Batch::MAX_ITEMS);

            if ($rows === []) {
                break;
            }

            if ($dryRun) {
                $cursor = (int) $rows[count($rows) - 1]['id'];
            }

            $rowIds = array_map(static fn ($row) => (int) $row['id'], $rows);
            $ids = array_map(static fn ($row) => (int) $row['object_id'], $rows);
            $version = (int) round(microtime(true) * 1000);

            $batch = new Batch();
            foreach ($this->serializer->serialize($ids, $store, $version) as $item) {
                $batch->add($item);
            }

            if ($batch->isEmpty()) {
                $this->outbox->complete($rowIds);

                continue;
            }

            if ($dryRun) {
                $result['batches']++;
                $result['items'] += $batch->count();

                continue;
            }

            // PASSES THE Batch OBJECT, NOT ITS ARRAY. `Client::ingestBatch()`
            // requires the type precisely so that the double wrap this code would
            // otherwise have reproduced is unrepresentable: `Batch::toArray()`
            // already returns `['items' => …]`, so handing that over produced
            // `{"items":{"items":[…]}}`, which is a perfectly valid array that
            // nothing local objects to and the service rejects with
            // `422 items.0.data is required` on the first real send.
            try {
                $response = $client->ingestBatch($batch);
                $ok = is_array($response) && ($response['ok'] ?? false);

                if (!$ok && is_array($response)) {
                    $result['reason'] = 'HTTP ' . ($response['status'] ?? 0)
                        . ' ' . (string) ($response['error'] ?? $response['body'] ?? '');
                }
            } catch (\Throwable $e) {
                $ok = false;
                $result['reason'] = $e->getMessage();
            }

            if (!$ok) {
                $this->outbox->release($rowIds);
                $this->settings->update([
                    'LAST_ERROR' => $result['reason'] !== '' ? $result['reason'] : 'batch rejected',
                ]);
                $result['failed'] = true;

                return $result;
            }

            $this->outbox->complete($rowIds);
            $result['batches']++;
            $result['items'] += $batch->count();
        }

        if (!$dryRun && $result['items'] > 0) {
            $this->settings->update(['LAST_SYNC' => gmdate('c'), 'LAST_ERROR' => '']);
        }

        return $result;
    }

    /**
     * The one store view this install indexes ([D-055]).
     *
     * Falls back to the default store rather than to admin scope (0): admin scope has
     * no base URL and no currency, so serializing against it would produce permalinks
     * to nowhere and prices in whatever currency the admin happens to carry. A
     * merchant who has not chosen yet gets their default storefront, which is the
     * answer they would have picked.
     */
    private function indexedStoreId(): int
    {
        $configured = (int) $this->settings->get('STORE_VIEW_ID', 0);

        return $configured > 0 ? $configured : (int) $this->storeManager->getDefaultStoreView()->getId();
    }
}
