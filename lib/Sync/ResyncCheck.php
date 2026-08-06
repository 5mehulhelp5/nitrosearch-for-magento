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

use NitroSearch\Api\Client;
use NitroSearch\Settings;

/**
 * The unattended heartbeat: keep the search key alive, and listen for a re-send.
 *
 * WHY THIS EXISTS, AND WHY ITS ABSENCE WAS WORSE THAN IT LOOKED. Until this class
 * existed, `status()` and `fetchSearchKey()` were reachable from exactly two lines
 * in the Configure screen — a merchant pressing a button. Nothing in this module
 * ever talked to the service unprompted. Three consequences, none of them visible
 * from the back office:
 *
 *  - **THE SCOPED SEARCH KEY EXPIRES.** The widget searches with a key that carries
 *    its own expiry. A shop that connects and is then left alone — which is every
 *    healthy shop — holds the key it was handed at onboarding until it stops
 *    working, and then storefront search simply returns nothing. No error, no
 *    notice, and the Configure screen still says connected.
 *  - **A RE-SEND REQUEST WAS NEVER HEARD.** The service can ask a shop to send its
 *    whole catalogue again, which is the only repair for items that were accepted
 *    on the wire and then turned out to be unusable. `Client::status()` has always
 *    parsed the `resync` block; nothing read it.
 *  - **OUT-OF-BAND VERIFICATION NEVER COMPLETED.** A shop verified by the service's
 *    own loopback rather than by a call we made would never pick up its key.
 *
 * The other two connectors have had this from the start. This is the parity gap,
 * and it is the one that decides whether a shortened key lifetime is safe to ship.
 *
 * IT RIDES THE EXISTING HEARTBEATS rather than owning a schedule. Neither major has
 * a job runner, so a request IS the worker: the cron endpoint if the merchant set
 * one up, and `PageLoadTick` if they did not. One mechanism to keep alive instead
 * of two, which on a platform with no clock is the whole argument.
 *
 * ⚠ IT MUST NOT BE GATED ON THERE BEING SYNC WORK. A shop with an empty outbox and
 * no active walk is not an idle shop to skip — it is the steady state of every
 * healthy catalogue, and precisely the one whose key runs out. `PageLoadTick`
 * schedules its deferred run when EITHER there is work OR this is due, and the two
 * conditions are kept apart there for that reason.
 */
final class ResyncCheck
{
    /**
     * Seconds between polls.
     *
     * Frequent enough that a re-send request is picked up while someone is still
     * watching for it, and that a key is renewed with months of margin to spare;
     * rare enough to be invisible — twelve small reads an hour, and only on shops
     * that are being visited at all.
     */
    const INTERVAL = 300;

    /**
     * Seconds between search-key REFRESHES, which is a different clock and a
     * different job from the poll above.
     *
     * ⚠ THE POLL CANNOT RENEW THE KEY. `/v1/status` does not carry one — only
     * `/v1/search-key` does — so a shop that never asks holds whatever it was given
     * at onboarding until it expires. Backfilling a MISSING key is not renewal:
     * an expired key is a non-empty string, so a gate of "fetch when we have none"
     * never fires for the shop that needs it, and storefront search goes quiet a
     * year after connecting with nothing on either side to say why.
     *
     * A day, matching the WooCommerce connector, against a key lifetime measured in
     * months — so a shop can miss hundreds of consecutive refreshes and still be
     * comfortably inside the margin.
     */
    const REFRESH_INTERVAL = 86400;

    /** @var Settings */
    private $settings;

    /** @var Client */
    private $client;

    /** @var FullSync */
    private $fullSync;

    public function __construct(Settings $settings, Client $client, FullSync $fullSync)
    {
        $this->settings = $settings;
        $this->client = $client;
        $this->fullSync = $fullSync;
    }

    /**
     * Whether a poll is due. Cheap: one already-cached settings read.
     *
     * SEPARATE FROM `maybeRun()` because the page-load fallback has to decide
     * whether to register a deferred run BEFORE doing any work, and asking this
     * question must not itself cost a request.
     *
     * @return bool
     */
    public function isDue()
    {
        if (!$this->settings->isConnected()) {
            return false;
        }

        if ((time() - (int) $this->settings->get('STATUS_CHECKED_AT', 0)) >= self::INTERVAL) {
            return true;
        }

        return $this->refreshDue();
    }

    /**
     * @return bool
     */
    private function refreshDue()
    {
        return (time() - (int) $this->settings->get('CONFIG_REFRESHED_AT', 0)) >= self::REFRESH_INTERVAL;
    }

    /**
     * One poll, if one is due.
     *
     * NEVER THROWS. Every caller is either a shopper's page or the merchant's cron,
     * and neither is a place to surface a housekeeping fault. A failed poll costs
     * one interval.
     *
     * @return bool whether a poll was actually made
     */
    public function maybeRun()
    {
        if (!$this->isDue()) {
            return false;
        }

        // The key refresh runs on its OWN clock and is decided BEFORE the poll, so
        // that a failing or unreachable status endpoint cannot stop the one job that
        // silently kills storefront search if it stops happening.
        $refreshDue = $this->refreshDue();

        $polled = false;
        if ((time() - (int) $this->settings->get('STATUS_CHECKED_AT', 0)) >= self::INTERVAL) {
            // Claimed BEFORE the request, not after. Two page loads landing in the
            // same second would otherwise both find the stamp stale and both poll;
            // and a service that hangs until the socket times out would be polled
            // again by the very next page view.
            $this->settings->update(array('STATUS_CHECKED_AT' => time()));
            $polled = true;
        }

        if ($refreshDue) {
            $this->refreshSearchKey();
        }

        if (!$polled) {
            return true;
        }

        try {
            $status = $this->client->status();
        } catch (\Exception $e) {
            return true;
        } catch (\Throwable $e) {
            return true;
        }

        if (empty($status['ok'])) {
            return true;
        }

        try {
            $this->backfillSearchKey($status);
            $this->handleResync($status);
        } catch (\Exception $e) {
            // Recorded where the merchant will see it, but never raised: the sync is
            // the job, this is housekeeping.
            $this->note($e->getMessage());
        } catch (\Throwable $e) {
            $this->note($e->getMessage());
        }

        return true;
    }

    /**
     * Re-fetch the scoped search key, whether or not one is already held.
     *
     * THIS IS THE JOB THE POLL CANNOT DO, and the reason this class exists at all.
     * The key the widget searches with carries a baked-in expiry; `/v1/status` never
     * carries a replacement, so the only way to get one is to ask `/v1/search-key`
     * on a clock. Left unasked, the key runs out and the storefront's search box
     * starts returning nothing — with the module still reporting connected, because
     * from its point of view it is.
     *
     * GATED ON `verified` OR SIMPLY HOLDING A KEY, belt and braces: the stored
     * `verified` flag can lag reality on a shop verified out of band, and a shop
     * that holds a key is refresh-eligible whatever the flag says. The service
     * answers 409 harmlessly if it truly is not verified.
     *
     * FAILURE IS SAFE BY DESIGN. `Client::fetchSearchKey()` refuses to overwrite a
     * stored key with anything that did not decode to the expected shape, so a bad
     * response leaves the working key in place, and the months of margin mean the
     * next day's attempt is always soon enough.
     */
    private function refreshSearchKey()
    {
        // ⚠ THE CLOCK IS STAMPED FIRST, BEFORE THE ELIGIBILITY TEST, and that
        // ordering is load-bearing. Stamping only on the eligible path leaves a
        // not-yet-verified shop permanently "refresh due", which makes `isDue()`
        // permanently true — so the page-load fallback schedules a deferred run on
        // every single tick forever, for a shop with nothing to do. It also made the
        // tick immediately after a successful backfill fetch the key a second time,
        // because the backfill happens on the poll's clock and never touched this
        // one. Both were caught by running it.
        //
        // Stamping unconditionally costs an ineligible shop nothing: the 300s poll
        // still backfills a missing key as soon as verification lands, so nothing
        // waits a day for something it needs sooner.
        $this->settings->update(array('CONFIG_REFRESHED_AT' => time()));

        if (!$this->settings->get('VERIFIED') && (string) $this->settings->get('SCOPED_SEARCH_KEY') === '') {
            return;
        }

        try {
            $this->client->fetchSearchKey();
        } catch (\Exception $e) {
            $this->note($e->getMessage());
        } catch (\Throwable $e) {
            $this->note($e->getMessage());
        }
    }

    /**
     * Pick the search key up when the service says we are verified and we hold none.
     *
     * THIS IS ALSO THE REPAIR PATH, not just onboarding. It is gated on the key being
     * absent rather than on some "have we ever fetched" flag, so a shop whose stored
     * key was lost — a restore from a backup taken before connection, a settings row
     * cleared by hand — heals itself on the next heartbeat instead of needing a
     * merchant to press a button they have no reason to think is needed.
     *
     * @param array<string, mixed> $status
     */
    private function backfillSearchKey(array $status)
    {
        if (empty($status['verified'])) {
            return;
        }

        if ((string) $this->settings->get('SCOPED_SEARCH_KEY') !== '') {
            return;
        }

        $this->client->fetchSearchKey();
    }

    /**
     * Start a requested walk, then confirm it.
     *
     * THE ORDER IS DELIBERATE. The token is recorded as acted on BEFORE the
     * confirmation is sent, so a confirmation that fails to arrive costs one retry
     * rather than a second full walk: the request stays outstanding, the next check
     * sees the same token, skips the walk it has already started, and simply tries
     * the confirmation again.
     *
     * Doing it the other way round — confirm first, record after — would restart the
     * whole catalogue every five minutes for as long as the confirmation kept
     * failing, which on a large shop is exactly the runaway load this module is
     * careful to avoid everywhere else.
     *
     * @param array<string, mixed> $status
     */
    private function handleResync(array $status)
    {
        $resync = isset($status['resync']) ? $status['resync'] : null;

        if (!is_array($resync) || empty($resync['required'])) {
            return;
        }

        $token = isset($resync['token']) ? (string) $resync['token'] : '';
        if ($token === '') {
            return;
        }

        if ((string) $this->settings->get('RESYNC_TOKEN_DONE', '') !== $token) {
            // ⚠ A WALK ALREADY IN FLIGHT IS NOT RESTARTED, and on this platform that
            // has to be checked HERE. OpenCart's `FullSync::start()` is unguarded —
            // its own docblock says "safe to call again — it restarts from the
            // beginning" — where PrestaShop's and WooCommerce's both resume instead.
            // So on a large catalogue mid-import, a resync request arriving through
            // this path would throw away every product already enumerated and begin
            // again from cursor zero.
            //
            // Letting the running walk finish satisfies the request in full: what the
            // service asked for is the whole catalogue re-sent, and that is precisely
            // what an in-flight walk is already doing. The token is still recorded, so
            // the request is not acted on twice.
            if (!$this->fullSync->isActive()) {
                $this->fullSync->start();
            }

            $this->settings->update(array('RESYNC_TOKEN_DONE' => $token));
        }

        $this->client->acknowledgeResync($token);
    }

    /**
     * @param string $message
     */
    private function note($message)
    {
        try {
            $this->settings->update(array(
                'LAST_ERROR' => substr('status check: ' . (string) $message, 0, 500),
            ));
        } catch (\Exception $e) {
            // Recording the failure failed. There is genuinely nowhere left to go.
        } catch (\Throwable $e) {
        }
    }
}
