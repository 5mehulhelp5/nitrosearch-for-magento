<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Model;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderInterface;
use NitroSearch\AdapterKit\CurrencyExponents;
use NitroSearch\Api\Client;
use NitroSearch\Settings;

/**
 * Search → order attribution.
 *
 * When the widget adds to cart it marks the store's OWN cart request with
 * `ns_search=1` and `ns_q=<term>`. Magento is mid-request at that moment, so the
 * product is noted against the shopper's session. When an order is placed, the items
 * that came from a search make up the ATTRIBUTED SLICE — its value and a hashed order
 * reference are queued, and the existing heartbeat sends them.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHAT NEVER LEAVES THE STORE: the real order id (hashed with the install id first),
 * the customer, the address, the payment, the basket contents. The wire carries a
 * value, a currency, an opaque reference, the ids of items that came from a search,
 * and the term that led to them. Nothing about who bought them.
 *
 * TWO STORES, DELIBERATELY, because they have different lifetimes and different
 * failure modes:
 *
 *  - The **cart marker** lives in Magento's checkout session. It dies with the
 *    session, and losing one costs a single attribution nobody would notice.
 *  - The **pending report** is a table row, because it must survive: written during
 *    checkout, sent minutes later. Losing it loses revenue data that cannot be
 *    reconstructed from anything else.
 *
 * **REPORTING IS NEVER DONE DURING CHECKOUT.** It rides the heartbeat. A checkout
 * must never be slowed, and must certainly never fail, because a search API was
 * briefly unreachable — and an order-placement observer is precisely the wrong place
 * to open a socket. This is the same conclusion the PrestaShop connector reached, and
 * the reason is identical on both.
 *
 * GATED ON THE MERCHANT'S ANALYTICS CHOICE. A merchant who declined to share search
 * data has their revenue left alone too; the toggle would otherwise mean less than it
 * says on the screen.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * TWO WAYS THIS REPORTED THE WRONG NUMBER, BOTH FIXED 2026-08-10, both invisible from
 * inside a working shop because each produced a well-formed payload the service was
 * happy to accept:
 *
 *  1. ORDERS WERE DROPPED ON ANSWERS THAT MEANT "ASK AGAIN". Every 4xx counted as
 *     handled, so a throttled report (429 — the endpoint takes sixty a minute per
 *     store), a shop not verified yet (409) and a suspended account (423) each deleted
 *     one order's revenue permanently. See {@see flush()}.
 *  2. THE MONEY WAS SCALED BY A HARDCODED HUNDRED. A JPY store reported a hundred
 *     times its revenue and a KWD store a tenth of it, on every order, since the
 *     module shipped. See {@see minorUnits()}.
 *
 * Neither logged anything. Both moved the single number this product is judged on.
 */
class OrderAttribution
{
    /** Attribution expires with the shopper's interest in it. */
    private const WINDOW_SECONDS = 604800;

    /**
     * Ceiling on marked products per session.
     *
     * Not a performance guard — a bound on how much a hostile or broken client can
     * push into a session it partly controls. A shopper with 25 search-driven items
     * in one basket is already unusual; one with 10,000 is not a shopper.
     */
    private const MAX_TRACKED = 25;

    /**
     * The service's own acceptance window for `occurred_at`, in days.
     *
     * A report whose timestamp is older than this is not refused — it is CLAMPED to
     * the edge of the window and recorded at that moment instead. Written down here
     * because {@see REPORT_TTL_DAYS} has to stay inside it, and a number that only
     * exists in another team's code is a number this module will drift away from.
     */
    private const SERVICE_CLAMP_DAYS = 8;

    /**
     * Reports older than this are abandoned rather than sent.
     *
     * ⚠ IT WAS 14, AND 14 IS PAST THE SERVICE'S CLAMP — corrected 2026-08-10 along
     * with the retry classification, because the two defects compound. Retries are now
     * possible across days rather than a single attempt at one, so a report can
     * genuinely still be in this queue a week later, and the arithmetic of the old
     * number matters for the first time:
     *
     *   A report sent on day 9 has its `occurred_at` clamped to "eight days ago",
     *   which is a MOVING value. The service dedupes on (store, order_ref,
     *   occurred_at), so an attempt on day 9 and an attempt on day 10 no longer
     *   collide — they land as TWO conversion rows for one order. Every other
     *   protection in this file exists to keep that from happening: the timestamp is
     *   stamped once, stored, and never recomputed, precisely so that a retry is
     *   indistinguishable from its predecessor. Sending past the clamp hands the
     *   service a reason to make them different anyway.
     *
     * So the queue gives up BEFORE the window closes rather than after it. Seven days
     * also matches the attribution window itself: a report nobody could deliver in a
     * week is one whose day has already been rolled up and read.
     */
    private const REPORT_TTL_DAYS = 7;

    private const SESSION_KEY = 'nitrosearch_attribution';

    private ResourceConnection $resource;
    private CheckoutSession $checkoutSession;
    private Settings $settings;

    public function __construct(
        ResourceConnection $resource,
        CheckoutSession $checkoutSession,
        Settings $settings
    ) {
        $this->resource = $resource;
        $this->checkoutSession = $checkoutSession;
        $this->settings = $settings;
    }

    /**
     * Note that this product reached the cart from a search.
     *
     * Called from the add-to-cart request, which is the only moment the link between
     * a search term and a product exists — the cart itself has no memory of how
     * anything got into it.
     */
    public function markFromSearch(int $productId, string $query): void
    {
        if ($productId <= 0 || !$this->settings->get('SHARE_SEARCH_DATA', true)) {
            return;
        }

        $marker = $this->readMarker();

        $marker['ids'][$productId] = true;

        // The FIRST term wins, not the last. A shopper who searches "yoga", adds a
        // mat, then searches "towel" and adds one has two attributions to one order;
        // the term that opened the session is the one that earned it, and letting the
        // last write win would credit whichever search happened to be most recent.
        if (($marker['q'] ?? '') === '' && $query !== '') {
            $marker['q'] = mb_substr($query, 0, 128);
        }

        if (count($marker['ids']) > self::MAX_TRACKED) {
            $marker['ids'] = array_slice($marker['ids'], -self::MAX_TRACKED, null, true);
        }

        $marker['at'] = time();

        $this->checkoutSession->setData(self::SESSION_KEY, $marker);
    }

    /**
     * Queue an attributed report for a placed order.
     *
     * NEVER THROWS, and the catch is not defensive padding. This runs inside order
     * placement: an exception escaping here does not lose an attribution, it loses
     * the merchant a sale. Whatever goes wrong, the order must complete.
     */
    public function orderPlaced(OrderInterface $order): void
    {
        try {
            $this->queueReport($order);
        } catch (\Throwable $e) {
            // Deliberately silent. An attribution is worth nothing next to a checkout.
        }
    }

    private function queueReport(OrderInterface $order): void
    {
        if (!$this->settings->isConnected() || !$this->settings->get('SHARE_SEARCH_DATA', true)) {
            return;
        }

        $orderId = (int) $order->getEntityId();

        // NO ID, NO REPORT — and this is a real branch, not defensive padding. The
        // first version of this observed `sales_order_place_after`, which runs before
        // the order is saved, so every report was written against id 0: locally they
        // overwrote each other through the unique key, and on the wire they hashed to
        // one constant `order_ref` that the service would dedupe into a single order
        // per store, forever. Writing nothing is recoverable; writing a colliding id
        // is a number a merchant reads and believes.
        if ($orderId <= 0) {
            return;
        }

        $marker = $this->readMarker();

        if ($marker['ids'] === []) {
            return;
        }

        $attributedIds = [];
        $valueCents = 0;

        // ONE CURRENCY, READ ONCE, USED FOR BOTH THE SCALING AND THE WIRE. The
        // exponent that turns a decimal into minor units is a property of the currency
        // being reported, so deriving the two from different expressions is how they
        // come to disagree. The order currency is what the shopper was charged in and
        // what the line totals below are denominated in; the base currency is only a
        // fallback for an order that somehow carries no order currency at all.
        $currency = strtoupper(trim((string) $order->getOrderCurrencyCode()));

        if ($currency === '') {
            $currency = strtoupper(trim((string) $order->getBaseCurrencyCode()));
        }

        foreach ($order->getItems() as $item) {
            // PARENT ITEMS ONLY. A configurable order line appears twice — once as
            // the parent carrying the price the shopper paid, once as the simple
            // child carrying zero. Counting both double-counts the line; counting the
            // child alone attributes zero. This is Magento-specific and has no
            // analogue in the other connectors' carts.
            if ($item->getParentItemId()) {
                continue;
            }

            $productId = (int) $item->getProductId();

            if (!isset($marker['ids'][$productId])) {
                continue;
            }

            $attributedIds[] = $productId;

            // row_total_incl_tax after discount — what the shopper actually paid for
            // this line, which is what a revenue number has to mean. Integer minor
            // units, because the wire is minor units everywhere.
            //
            // THE SUBTRACTION IS DONE IN MINOR UNITS, NOT IN FLOATS, and the order of
            // those two operations is the whole point: `$rowTotal - $discount` in
            // binary floating point is where 19.99 - 5.00 becomes 14.989999999999998,
            // and the conversion below reads digits rather than rounding a float back
            // out of trouble.
            $rowTotal = $item->getRowTotalInclTax() ?? $item->getRowTotal() ?? 0;
            $discount = $item->getDiscountAmount() ?? 0;

            $valueCents += max(
                0,
                self::minorUnits($rowTotal, $currency) - self::minorUnits($discount, $currency)
            );
        }

        if ($attributedIds === []) {
            return;
        }

        $connection = $this->resource->getConnection();

        // insertOnDuplicate, not insert: the unique key on order_id means an order
        // placed twice by a retried request updates rather than throwing — and
        // throwing here would be caught and swallowed, silently losing the report.
        $connection->insertOnDuplicate(
            $this->resource->getTableName('nitrosearch_order_report'),
            [
                'order_id' => $orderId,
                'value_cents' => $valueCents,
                'currency' => $currency,
                'item_ids' => implode(',', $attributedIds),
                'q' => (string) ($marker['q'] ?? ''),
                'occurred_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['value_cents', 'item_ids', 'q']
        );

        // The marker has done its job. Clearing it stops a second order in the same
        // session inheriting the first one's search term.
        $this->checkoutSession->unsetData(self::SESSION_KEY);
    }

    /**
     * Send queued reports. Called from the heartbeat, never from checkout.
     *
     * STOPS ON THE FIRST RETRYABLE ANSWER rather than burning through the queue
     * against a service that is plainly unreachable — the rows stay, in order, and the
     * next heartbeat tries again five minutes later. A report the service refuses on
     * its merits is deleted instead, so a poison row cannot park itself at the head of
     * the queue and block every later order behind it.
     *
     * ────────────────────────────────────────────────────────────────────────────
     * WHICH ANSWERS ARE WHICH IS THE CLIENT'S JOB, AND IT USED TO GET THREE OF THEM
     * WRONG (fixed 2026-08-10). {@see Client::reportOrder()} answered a bare boolean
     * and called EVERY 4xx handled, so this loop deleted the row. Three of those 4xx
     * are conditions a shop comes back from within minutes — 429 throttled, 409 not
     * verified yet, 423 account suspended — and each one silently destroyed one
     * order's attributed revenue, permanently, with nothing left anywhere to show a
     * number had gone missing. The 429 is the expensive one: the endpoint takes sixty
     * reports a minute per store, so the loss lands hardest during a flash sale, and
     * the hour a merchant most wants to point at is the hour that under-reports.
     *
     * THE BOUND ON RETRYING IS THE AGE OF THE REPORT, NOT AN ATTEMPT COUNT. This queue
     * has no attempts column, so a row that keeps earning "come back later" is retried
     * every heartbeat until {@see expireStale()} drops it at {@see REPORT_TTL_DAYS}.
     * That is bounded, ordered and self-clearing — and it is why the head-of-queue
     * distinction above matters so much: the only thing that can hold the queue up is
     * a condition the service itself says will pass.
     */
    public function flush(int $limit = 10): int
    {
        if (!$this->settings->isConnected() || !$this->settings->get('SHARE_SEARCH_DATA', true)) {
            return 0;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('nitrosearch_order_report');

        $this->expireStale();

        $rows = $connection->fetchAll(
            $connection->select()->from($table)->order('id ASC')->limit($limit)
        );

        if ($rows === []) {
            return 0;
        }

        $client = new Client($this->settings, (string) $this->settings->get('SITE_URL'));
        $sent = 0;

        foreach ($rows as $row) {
            $occurredAt = self::wireTimestamp((string) $row['occurred_at']);

            if ($occurredAt === '') {
                // Unreadable stamp. It cannot be repaired by trying again, and sending
                // it without one is not on offer — a report the service stamps on
                // arrival gets a different timestamp on every attempt, which is the
                // duplicate-revenue failure this file is written against. Dropped, and
                // said out loud, because a report that vanishes quietly is the thing
                // being fixed here.
                $connection->delete($table, ['id = ?' => (int) $row['id']]);
                $this->note('report for order ' . (int) $row['order_id'] . ' dropped: unreadable timestamp');
                continue;
            }

            $outcome = $client->reportOrder([
                'order_id' => (int) $row['order_id'],
                'value_cents' => (int) $row['value_cents'],
                'currency' => (string) $row['currency'],
                'occurred_at' => $occurredAt,
                'item_ids' => array_filter(explode(',', (string) $row['item_ids'])),
                'q' => (string) $row['q'],
            ]);

            // RETRY MEANS LEAVE THE ROW EXACTLY AS IT IS. Not "delete and hope", and
            // not "rewrite and re-send": the payload the next attempt builds has to be
            // byte-for-byte the one this attempt sent, because the service dedupes on
            // (store, order_ref, occurred_at) and a payload that differs by so much as
            // its timestamp is a second order as far as that key is concerned.
            if (empty($outcome['done'])) {
                break;
            }

            $connection->delete($table, ['id = ?' => (int) $row['id']]);
            $sent++;
        }

        return $sent;
    }

    /**
     * The stored timestamp, as the wire wants it, DERIVED WITHOUT A CLOCK.
     *
     * The row holds UTC wall time, written once when the order was placed. All this
     * does is move it into ISO-8601: `2026-08-10 14:03:11` → `2026-08-10T14:03:11Z`.
     *
     * ⚠ IT IS DELIBERATELY STRING SURGERY AND NOT `gmdate('c', strtotime($stored))`,
     * which is what stood here until 2026-08-10 and had two faults, the second of them
     * serious:
     *
     *  - `strtotime()` reads a bare `Y-m-d H:i:s` in PHP's AMBIENT timezone, while the
     *    value was written with `gmdate()` in UTC. On any store whose PHP is not set to
     *    UTC that silently shifted every reported order by the host's offset, moving
     *    revenue across day boundaries in the merchant's own rollup.
     *  - Worse, it made the wire value depend on process state rather than on the row.
     *    A retry running under a different ambient timezone — a cron container, a
     *    module that calls `date_default_timezone_set()`, a DST transition on a host
     *    set to local time — produces a DIFFERENT `occurred_at` for the same order, and
     *    a different `occurred_at` is a different dedupe key: the merchant's revenue
     *    counted twice. Retries were rare before this session; they are the normal path
     *    now, so a timestamp that is a pure function of the stored string is no longer
     *    a nicety.
     *
     * Anything that does not look like a stored timestamp returns '' and the caller
     * refuses to send, rather than substituting the current time — inventing a
     * timestamp is precisely how one order becomes several.
     */
    private static function wireTimestamp(string $stored): string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', trim($stored), $parts) !== 1) {
            return '';
        }

        return $parts[1] . 'T' . $parts[2] . 'Z';
    }

    /**
     * A decimal amount as a whole number of the currency's smallest unit.
     *
     * ⚠ THIS REPLACES A HARDCODED `× 100`, WHICH IS THE COSTLIEST LINE THIS MODULE HAS
     * SHIPPED (fixed 2026-08-10). What stood here was:
     *
     *     $valueCents += (int) round(max(0.0, $rowTotal - $discount) * 100);
     *
     * Right for dollars, euros and pounds. Wrong for about fifty currencies, in both
     * directions and in complete silence:
     *
     *   JPY has no minor unit, so a ¥12,000 order was reported as 1,200,000 minor
     *   units — a HUNDRED TIMES the revenue actually taken. KWD, BHD, JOD, OMR, TND
     *   and LYD have three, so those stores reported a TENTH of theirs.
     *
     * Nothing anywhere objects. The payload is well-formed, the service accepts it,
     * and the merchant is shown a plausible number that is wrong by two orders of
     * magnitude — on every order, since the connector shipped. The exponent table this
     * now consults is generated from the same source the service uses, and was already
     * vendored in this module and already used for catalogue prices; only this one
     * line was written from memory.
     *
     * SCALED BY MOVING DIGITS, NEVER BY MULTIPLYING A FLOAT. `(int) (19.99 * 100)` is
     * 1998 — 19.99 has no exact binary representation — and `round()` only papers over
     * that for the cases someone thought to try. Reading the decimal as text has no
     * such cases. The digit after the cut rounds half away from zero, which is what a
     * merchant's own totals do; a currency's exponent is only reached at all by an
     * amount carrying more places than the currency has, which Magento's four-decimal
     * columns produce routinely.
     *
     * @param mixed $amount a decimal string ('19.9900') or a float, as the sales item
     *                      hands it over: from the database it is a string, mid-request
     *                      it is a float, and both have to give the same answer
     */
    private static function minorUnits($amount, string $currency): int
    {
        $exponent = CurrencyExponents::for($currency);

        // A float is turned into a decimal STRING before anything is read off it, at
        // more places than any currency has, so the representation error stays where
        // it belongs — below the digits anyone is charged in.
        $decimal = is_string($amount) || is_int($amount)
            ? trim((string) $amount)
            : sprintf('%.6F', (float) $amount);

        if (preg_match('/^-?\d+(\.\d+)?$/', $decimal) !== 1) {
            return 0;
        }

        $negative = $decimal[0] === '-';
        $decimal = ltrim($decimal, '-');

        $parts = explode('.', $decimal, 2);
        $whole = $parts[0];
        // One digit past the cut, because that digit decides the rounding.
        $fraction = str_pad($parts[1] ?? '', $exponent + 1, '0');

        $minor = (int) ($whole . substr($fraction, 0, $exponent));

        if ((int) $fraction[$exponent] >= 5) {
            $minor++;
        }

        return $negative ? -$minor : $minor;
    }

    /**
     * Abandon reports too old to be worth sending.
     *
     * Without this, a store disconnected for a month reconnects and floods the
     * service with stale revenue events — and analytics that arrive weeks late are
     * worse than absent, because they silently move a number somebody has already
     * read and acted on.
     *
     * IT IS ALSO THE ONLY BOUND ON RETRYING, now that a retryable answer keeps a row
     * in the queue instead of deleting it, and the threshold is therefore clamped
     * INSIDE the service's own acceptance window rather than merely set to a
     * sensible-looking number of days. Past that window the service rewrites a
     * report's timestamp to the edge of it, and the edge moves with the clock, so two
     * attempts on two days would no longer dedupe against each other. Expressed as
     * arithmetic on both constants because the invariant is the relationship between
     * them, and a comment asserting it does not survive somebody editing one.
     *
     * WHAT IT DELETES IS RECORDED. This is a permanent, silent loss of revenue data —
     * the exact shape of failure the retry work exists to stop — so it does not also
     * get to happen without a trace.
     */
    private function expireStale(): void
    {
        $connection = $this->resource->getConnection();

        $days = min(self::REPORT_TTL_DAYS, self::SERVICE_CLAMP_DAYS - 1);

        $abandoned = (int) $connection->delete(
            $this->resource->getTableName('nitrosearch_order_report'),
            ['occurred_at < ?' => gmdate('Y-m-d H:i:s', time() - ($days * 86400))]
        );

        if ($abandoned > 0) {
            $this->note($abandoned . ' attributed order(s) abandoned unsent after ' . $days . ' days');
        }
    }

    /**
     * Say why attributed revenue is missing, where the merchant can see it.
     *
     * The Configure screen already surfaces `LAST_ERROR`, and the heartbeat already
     * writes order-report faults into it, so this follows the module's own precedent
     * rather than adding a second error channel nothing renders. Locale-neutral and
     * bounded, like every other writer of that key: a stored string outlives the
     * locale that was current when it was written.
     */
    private function note(string $message): void
    {
        $this->settings->update(['LAST_ERROR' => substr('order report: ' . $message, 0, 500)]);
    }

    /**
     * @return array{ids: array<int, bool>, q: string, at: int}
     */
    private function readMarker(): array
    {
        $marker = $this->checkoutSession->getData(self::SESSION_KEY);

        if (!is_array($marker) || !isset($marker['ids']) || !is_array($marker['ids'])) {
            return ['ids' => [], 'q' => '', 'at' => 0];
        }

        // Expired markers are dropped rather than used. A basket abandoned for a week
        // and completed later did not come from that search in any sense a merchant
        // would recognise.
        if ((time() - (int) ($marker['at'] ?? 0)) > self::WINDOW_SECONDS) {
            return ['ids' => [], 'q' => '', 'at' => 0];
        }

        return [
            'ids' => $marker['ids'],
            'q' => (string) ($marker['q'] ?? ''),
            'at' => (int) ($marker['at'] ?? 0),
        ];
    }
}
