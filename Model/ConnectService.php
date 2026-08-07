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
use NitroSearch\Api\Client;
use NitroSearch\Settings;

/**
 * Connect, verify, disconnect — the three things the admin screen's buttons do.
 *
 * WHY THIS IS MAGENTO-NATIVE WHEN `Client`, `Hmac` AND `VerifyChallenge` ARE
 * VENDORED VERBATIM. The line is drawn at the wire, deliberately.
 *
 * Anything that shapes a BYTE ON THE WIRE is shared byte-identically across all four
 * connectors: the canonical signing string, the verify proof, the HTTP client, the
 * settings key table. If those drift between platforms the failure is a 401 that
 * looks like a credential problem, and it is discovered on a merchant's store.
 *
 * Everything AROUND the wire is platform-shaped by definition. OpenCart's equivalent
 * of this class takes its hand-wired `Runner` and OpenCart's `$db`; Magento has a DI
 * container and a query builder, and pretending otherwise would mean carrying a
 * dependency-injection framework we already have one of. Same reasoning as
 * `Model\Outbox` and `Model\Drain`, and it is why `lib/` contains no database code at
 * all in this module where the OpenCart copy does.
 */
class ConnectService
{
    private Settings $settings;
    private Outbox $outbox;
    private StoreManagerInterface $storeManager;
    private CacheTag $cacheTag;

    public function __construct(
        Settings $settings,
        Outbox $outbox,
        StoreManagerInterface $storeManager,
        CacheTag $cacheTag
    ) {
        $this->settings = $settings;
        $this->outbox = $outbox;
        $this->storeManager = $storeManager;
        $this->cacheTag = $cacheTag;
    }

    /**
     * Connect the store, then immediately ask to be verified.
     *
     * THE TWO STEPS ARE SEPARATE, AND FAILING THE SECOND IS NOT FAILING THE FIRST.
     * Connecting stores credentials; verification is the service fetching this
     * store's public route from the outside, which cannot succeed on a localhost
     * install, behind basic auth, or on a staging host with a password. Those stores
     * are correctly connected and simply not yet verified.
     *
     * Reporting that as a connect failure sends a merchant looking for a problem in
     * the wrong place, and tempts them to disconnect and retry — which changes
     * nothing and costs them their credentials. Every connector on this project
     * learned that the same way.
     *
     * @return array{ok: bool, connected: bool, verified: bool, error: string, reason: string}
     */
    public function connect(): array
    {
        $siteUrl = $this->siteUrl();

        // Persisted BEFORE the request, because it is a SIGNING INPUT: the canonical
        // string covers site_url, so the value used to sign and the value stored
        // must be the same one. Deriving it twice invites them to differ.
        $this->settings->update(['SITE_URL' => $siteUrl]);

        $client = new Client($this->settings, $siteUrl);
        $result = $client->connect();

        if (empty($result['ok'])) {
            return [
                'ok' => false,
                'connected' => false,
                'verified' => false,
                'error' => (string) ($result['error'] ?? 'connect failed'),
                'reason' => '',
            ];
        }

        $verification = $client->verify();

        return [
            'ok' => true,
            'connected' => true,
            'verified' => !empty($verification['verified']),
            'error' => '',
            'reason' => (string) ($verification['reason'] ?? ''),
        ];
    }

    /**
     * Re-ask for verification and pick up what follows from it.
     *
     * The button a merchant presses after fixing their hosting. `fetchSearchKey()`
     * runs on success because verification usually happens through the service's own
     * loopback rather than through a call we made — so a store can become verified
     * without this module ever learning it, and without the key the storefront widget
     * has nothing to search with.
     *
     * @return array{ok: bool, verified: bool, reason: string}
     */
    public function refresh(): array
    {
        if (!$this->settings->isConnected()) {
            return ['ok' => false, 'verified' => false, 'reason' => 'not_connected'];
        }

        $client = new Client($this->settings, $this->siteUrl());
        $verification = $client->verify();

        if (!empty($verification['verified'])) {
            $client->fetchSearchKey();

            // THE KEY JUST CHANGED, SO THE CACHED PAGES CARRYING THE OLD ONE ARE NOW
            // WRONG. This call is the other half of the cache tag on
            // `Block\Storefront\Config`; without it that tag is decoration and a
            // shopper on a cached page keeps querying with a dead key. It belongs
            // next to the write rather than in a sweep, because a sweep might not
            // have run yet when the next shopper arrives.
            $this->cacheTag->invalidate();
        }

        $client->status();

        return [
            'ok' => !empty($verification['ok']),
            'verified' => !empty($verification['verified']),
            'reason' => (string) ($verification['reason'] ?? ''),
        ];
    }

    /** Queue the whole catalogue. The full walk is the correctness argument. */
    public function startFullSync(): int
    {
        return $this->outbox->enqueueAll();
    }

    /**
     * Forget everything.
     *
     * PURGES RATHER THAN FLAGGING. A disconnected store that keeps its sync secret is
     * a credential sitting in a database for no reason, and `Settings::purge()`
     * removes the whole config group — including keys a newer version introduced —
     * rather than a list someone has to remember to extend.
     */
    public function disconnect(): void
    {
        $this->settings->purge();
    }

    /**
     * THE STORE'S OWN BASE URL, FROM THE INDEXED STORE VIEW.
     *
     * Not from the admin scope, which has no meaningful base URL, and not from
     * whichever store happens to be current when an admin controller runs. This is a
     * signing input and it is what the service will fetch to verify us, so a wrong
     * value here fails verification with a message about the merchant's hosting.
     */
    private function siteUrl(): string
    {
        $configured = (int) $this->settings->get('STORE_VIEW_ID', 0);

        $store = $configured > 0
            ? $this->storeManager->getStore($configured)
            : $this->storeManager->getDefaultStoreView();

        return rtrim((string) $store->getBaseUrl(), '/');
    }
}
