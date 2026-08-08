<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Model;

use NitroSearch\Api\Client;
use NitroSearch\Settings;
use NitroSearch\Sync\ResyncCheck;

/**
 * The two clocks.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THEY CANNOT BE MERGED, AND THIS IS THE MOST EXPENSIVE THING IN THIS MODULE TO GET
 * WRONG. Two jobs, two intervals, two stored timestamps:
 *
 *   poll     300 s    STATUS_CHECKED_AT     GET /v1/status      resync flag, verified
 *                                                               flag, plan and limits
 *   refresh  86,400 s CONFIG_REFRESHED_AT   GET /v1/search-key  the key itself
 *
 * **`/v1/status` CARRIES NO KEY.** A store that only ever polls holds the key it was
 * issued at onboarding until that key expires — at which point storefront search
 * silently returns nothing while the admin screen still says "connected". That is the
 * failure mode this whole class exists to prevent, and it is invisible from every
 * surface a merchant looks at.
 *
 * **BACKFILLING A MISSING KEY IS NOT RENEWAL.** An expired key is a non-empty string,
 * so a gate of "fetch one when we have none" never fires for the store that needs it
 * most. The two are separate paths in {@see ResyncCheck} for exactly that reason.
 *
 * The gating, the stamp-before-eligibility ordering, the never-overwrite-a-key-with-a
 * -malformed-200 rule and the record-then-walk-then-acknowledge order for a resync all
 * live in `ResyncCheck`, byte-identical across all four connectors. This class is the
 * Magento wiring around it — and one thing that is genuinely Magento's alone.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE MAGENTO-ONLY PART: A NEW KEY MEANS CACHED PAGES ARE NOW WRONG.
 *
 * Every Magento store has a full page cache, which none of the other three platforms
 * has by default. The config blob carrying the scoped key is baked into cached pages,
 * so the moment the key changes those pages are serving a credential that no longer
 * works.
 *
 * So the key is read before and after, and the cache tag is cleaned ONLY IF IT
 * ACTUALLY CHANGED. Comparing rather than invalidating on every refresh attempt
 * matters: the refresh clock fires daily whether or not the service issues a new key,
 * and clearing a busy store's page cache once a day for nothing is a cost with no
 * benefit. It also means a failed or malformed refresh — which correctly leaves the
 * old key in place — does not trigger a purge either.
 *
 * NEVER THROWS. This runs from the merchant's cron. `ResyncCheck::maybeRun()` is
 * already documented as never throwing; the comparison around it is wrapped anyway,
 * because a cache backend that is briefly unavailable must not turn a housekeeping
 * tick into a fatal in someone's cron log.
 */
class Clocks
{
    private Settings $settings;
    private FullWalk $fullWalk;
    private CacheTag $cacheTag;
    private OrderAttribution $attribution;

    public function __construct(
        Settings $settings,
        FullWalk $fullWalk,
        CacheTag $cacheTag,
        OrderAttribution $attribution
    ) {
        $this->settings = $settings;
        $this->fullWalk = $fullWalk;
        $this->cacheTag = $cacheTag;
        $this->attribution = $attribution;
    }

    /**
     * Run whichever clock is due.
     *
     * @return array{ran: bool, keyChanged: bool, reported: int, reportError: string}
     */
    public function run(): array
    {
        if (!$this->settings->isConnected()) {
            return ['ran' => false, 'keyChanged' => false, 'reported' => 0, 'reportError' => ''];
        }

        $before = (string) $this->settings->get('SCOPED_SEARCH_KEY');

        $check = new ResyncCheck(
            $this->settings,
            new Client($this->settings, (string) $this->settings->get('SITE_URL')),
            $this->fullWalk
        );

        $ran = (bool) $check->maybeRun();

        $after = (string) $this->settings->get('SCOPED_SEARCH_KEY');

        // ONLY ON A REAL CHANGE. Not on every refresh, not on every tick.
        $keyChanged = $after !== '' && $after !== $before;

        if ($keyChanged) {
            try {
                $this->cacheTag->invalidate();
            } catch (\Throwable $e) {
                // A cache backend blinking must not fail the tick. The next shopper
                // on an uncached page gets the new key regardless; the cost of
                // swallowing this is a slower recovery, and the cost of not
                // swallowing it is a fatal in a merchant's cron log.
            }
        }

        // ATTRIBUTED ORDERS RIDE THIS HEARTBEAT, and they run on EVERY tick rather
        // than only when a clock was due. The clocks are throttled because polling
        // costs a request whether or not anything changed; a flush costs nothing when
        // the queue is empty, and a merchant's revenue data should not wait up to
        // five minutes longer than it has to because the poll happened to be recent.
        // THE CATCH RECORDS THE REASON RATHER THAN HIDING IT. A silent catch around
        // a whole subsystem is how a broken flush looks identical to an empty queue —
        // which cost a diagnosis here: "orders sent: 0" is what both a working idle
        // heartbeat and a fatal inside `flush()` print. Swallowing is still right,
        // because a cron tick must not fatal on a reporting fault, but swallowing
        // WITHOUT a trace is not.
        $reported = 0;
        $reportError = '';

        try {
            $reported = $this->attribution->flush();
        } catch (\Throwable $e) {
            $reportError = $e->getMessage();
            $this->settings->update(['LAST_ERROR' => 'order report: ' . $reportError]);
        }

        return [
            'ran' => $ran,
            'keyChanged' => $keyChanged,
            'reported' => $reported,
            'reportError' => $reportError,
        ];
    }
}
