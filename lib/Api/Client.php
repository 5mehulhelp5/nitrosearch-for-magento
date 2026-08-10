<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

namespace NitroSearch\Api;

use NitroSearch\AdapterKit\Batch;
use NitroSearch\Settings;
use NitroSearch\Support\Hmac;

/**
 * HTTP client for the NitroSearch service.
 *
 * `connect` is unauthenticated — it is how a shop first announces itself and
 * receives its credentials. Everything else is HMAC-signed with the sync secret.
 *
 * EVERY METHOD RETURNS AN ARRAY AND NEVER THROWS. A sync fault must never surface
 * as a 500 on a merchant's shop or a fatal inside a cron tick: the shop's own
 * pages are not ours to break, and an exception escaping a catalogue hook would
 * take down a product save.
 *
 * FRAMEWORK-FREE, LIKE EVERYTHING IN src/. The shop's own URL is passed in rather
 * than read from OpenCart, because the two majors expose it differently and this
 * class is shared by both builds verbatim.
 */
final class Client
{
    /** @var Settings */
    private $settings;

    /** @var string this shop's canonical base URL, as the service will see it */
    private $siteUrl;

    /**
     * HTTP statuses on an order report that mean "come back and ask again" rather
     * than "this is the answer". Every 5xx is retryable too, and so is a transport
     * failure, which has no status at all — both are tested separately in
     * {@see reportOrder()} rather than enumerated here.
     *
     * @var array<int, int>
     */
    private static $orderRetryCodes = array(401, 408, 409, 423, 425, 429);

    public function __construct(Settings $settings, $siteUrl)
    {
        $this->settings = $settings;
        $this->siteUrl = rtrim((string) $siteUrl, '/');
    }

    /**
     * Register this shop and persist the returned credentials.
     *
     * @return array{ok: bool, error?: string}
     */
    public function connect()
    {
        $headers = array(
            'Content-Type: application/json',
            'Accept: application/json',
        );

        $token = (string) $this->settings->get('CONNECT_TOKEN');
        if ($token !== '') {
            $headers[] = 'X-NS-Connect-Token: ' . $token;
        }

        $body = json_encode(array(
            'site_url' => $this->siteUrl,
            'install_id' => $this->settings->installId(),
            // DECLARING THE PLATFORM IS NOT COSMETIC, AND IT MUST NOT BE HARDCODED
            // HERE. It is what makes the service hand back THIS platform's widget
            // bundle rather than another's, and what namespaces our engine document
            // ids by object type.
            //
            // THIS LINE READ `'platform' => 'opencart'` IN THE COPY THIS FILE WAS
            // VENDORED FROM, and it survived into the Magento module unnoticed. The
            // consequence was not subtle: the service registered a Magento store as
            // OpenCart and would have served it the OPENCART widget bundle — whose
            // loader reads `search` as the query parameter where Magento uses `q`,
            // and whose cart endpoint is a route Magento does not have. A storefront
            // that renders the entire catalogue under any search term is exactly the
            // failure [D-039] cost, arriving by a different door.
            //
            // It was caught by connecting a real store and reading the platform back
            // out of the service, which is the only place the wrong value is visible:
            // nothing local objects to it, and every request signs and succeeds.
            //
            // So the slug comes from the module's own settings. `lib/` is shared
            // byte-identically across four connectors, and a per-platform constant in
            // shared code is a contradiction that only holds while there is one
            // consumer.
            'platform' => (string) $this->settings->get('PLATFORM'),
        ));

        $res = $this->request('POST', $this->settings->apiUrl() . '/v1/connect', $headers, $body, 20);

        if (!$res['ok'] && $res['status'] === 0) {
            return array('ok' => false, 'error' => $res['error']);
        }

        $decoded = json_decode($res['body'], true);
        if ($res['status'] !== 201 || !is_array($decoded)) {
            return array('ok' => false, 'error' => self::explain($res['status'], $res['body']));
        }

        $this->settings->update(array(
            'CONNECTED' => true,
            'SITE_URL' => $this->siteUrl,
            'STORE_ID' => self::pluck($decoded, array('store_id')),
            'SYNC_KEY_ID' => self::pluck($decoded, array('sync', 'key_id')),
            'SYNC_SECRET' => self::pluck($decoded, array('sync', 'secret')),
        ));

        // The search block only arrives once the shop is verified; on a fresh
        // connect it is absent and that is the normal path, not a failure.
        if (isset($decoded['search']) && is_array($decoded['search'])) {
            $this->storeSearch($decoded['search']);
        }
        if (isset($decoded['widget']) && is_array($decoded['widget'])) {
            $this->storeWidget($decoded['widget']);
        }
        if (isset($decoded['events']) && is_array($decoded['events'])) {
            $this->storeEvents($decoded['events']);
        }

        return array('ok' => true);
    }

    /**
     * Ask the service to prove control of this shop's hostname.
     *
     * It answers by fetching this module's public verify controller over a
     * server-to-server request; we never prove anything from inside this call. When
     * the shop cannot be reached from the outside — a firewall, a staging host,
     * localhost — verification simply stays pending, which is correct rather than an
     * error to surface loudly.
     *
     * @return array{ok: bool, verified: bool, reason: string}
     */
    public function verify()
    {
        $res = $this->signed('POST', '/v1/verify', '');
        if (!$res['ok']) {
            return array('ok' => false, 'verified' => false, 'reason' => 'unreachable');
        }

        $body = is_array($res['json']) ? $res['json'] : array();
        $verified = !empty($body['verification']['verified']);

        if ($verified) {
            $this->settings->update(array('VERIFIED' => true));
            if (isset($body['search']) && is_array($body['search'])) {
                $this->storeSearch($body['search']);
            }
            if (isset($body['widget']) && is_array($body['widget'])) {
                $this->storeWidget($body['widget']);
            }
        }

        return array(
            'ok' => true,
            'verified' => $verified,
            'reason' => isset($body['verification']['reason']) ? (string) $body['verification']['reason'] : '',
        );
    }

    /**
     * Poll plan / limit / verified / indexed count.
     *
     * @return array<string, mixed>
     */
    public function status()
    {
        // A SHORT BUDGET, because the unattended heartbeat calls this and that rides
        // a shopper's page load on shops with no cron. Housekeeping does not get to
        // hold a storefront request open for twenty seconds; the next tick is five
        // minutes away and losing one costs nothing.
        $res = $this->signed('GET', '/v1/status', '', 8);
        $body = is_array($res['json']) ? $res['json'] : array();

        $status = array(
            'ok' => $res['ok'],
            'verified' => !empty($body['verified']),
            'claimed' => !empty($body['claimed']),
            'plan' => isset($body['plan']) ? (string) $body['plan'] : '',
            'product_limit' => isset($body['product_limit']) ? (int) $body['product_limit'] : 0,
            'product_count' => isset($body['product_count']) ? (int) $body['product_count'] : 0,
            'at_limit' => !empty($body['at_limit']),
            // Present ONLY while the service is asking this shop to re-send its whole
            // catalogue. Its ABSENCE is the signal, so there is nothing to compare
            // against when it is missing.
            'resync' => isset($body['resync']) && is_array($body['resync']) ? $body['resync'] : null,
        );

        // Persist only when the body really looks like a status response. A 200
        // carrying something else — a proxy notice, a WAF interstitial, a host's
        // injected footer — must never flatten real stored state to defaults.
        if ($res['ok'] && array_key_exists('verified', $body)) {
            $this->settings->update(array(
                'VERIFIED' => $status['verified'],
                'CLAIMED' => $status['claimed'],
                'PLAN' => $status['plan'],
                'PRODUCT_LIMIT' => $status['product_limit'],
                'PRODUCT_COUNT' => $status['product_count'],
                'AT_LIMIT' => $status['at_limit'],
            ));
        }

        return $status;
    }

    /**
     * Fetch and persist the scoped search key, available once verified.
     *
     * This is how the widget gets its key when verification happened out of band —
     * the service's loopback, not a call we made.
     *
     * @return array{ok: bool, error?: string}
     */
    public function fetchSearchKey()
    {
        // Bounded for the same reason as `status()`: the daily refresh rides a
        // shopper's page load on shops with no cron.
        $res = $this->signed('GET', '/v1/search-key', '', 8);
        if (!$res['ok']) {
            return array('ok' => false, 'error' => 'HTTP ' . $res['status']);
        }

        $body = $res['json'];
        if (!is_array($body) || !isset($body['scoped_search_key']) || $body['scoped_search_key'] === '') {
            // A 200 whose body did not decode to the expected shape must never touch
            // stored state: blanking a working key kills storefront search until the
            // next refresh. A stale-but-valid key beats no key.
            return array('ok' => false, 'error' => 'malformed response body');
        }

        $this->storeSearch($body);

        return array('ok' => true);
    }

    /**
     * Confirm that a requested re-send has been started.
     *
     * The service keeps asking until it hears this, so the request survives a shop
     * that was switched off, mid-upgrade, or simply not being visited. Returns
     * whether it landed; the caller retries on the next heartbeat if it did not.
     *
     * A SHORTER TIMEOUT THAN THE INGEST CALLS, deliberately. This rides a shopper's
     * page load on shops with no cron, and it is housekeeping — a service taking
     * fifteen seconds to accept an acknowledgement is a service we should stop
     * waiting for and try again in five minutes.
     *
     * @param string $token
     *
     * @return bool
     */
    public function acknowledgeResync($token)
    {
        $token = (string) $token;
        if ($token === '') {
            return false;
        }

        $res = $this->signed('POST', '/v1/resync/ack', json_encode(array('token' => $token)), 15);

        return $res['ok'];
    }

    /**
     * Send one signed batch of catalogue changes.
     *
     * TAKES A `Batch`, NOT AN ARRAY, AND THAT IS THE FIX FOR A REAL BUG. This used to
     * accept an array and wrap it as `['items' => …]` — but `Batch::toArray()` already
     * returns that shape, so the caller handing one over produced
     * `{"items":{"items":[…]}}`. Both are perfectly valid arrays, nothing local
     * objected, and the service answered `422 items.0.data is required` on the first
     * real send.
     *
     * Requiring the type makes the double wrap unrepresentable rather than merely
     * corrected: there is no longer an array-shaped thing for a caller to pass.
     *
     * @return array{ok: bool, status: int, json: mixed, body: string, error: string}
     */
    public function ingestBatch(Batch $batch)
    {
        return $this->signed('POST', '/v1/ingest/batch', $batch->toJson());
    }

    /**
     * Report one attributed order.
     *
     * THE REAL ORDER ID NEVER LEAVES THE SHOP. It is hashed with this install's own
     * id first, which gives the service a reference that is stable for this shop —
     * so repeat reports of the same order collapse — while being useless anywhere
     * else and reversible nowhere. Nor does the customer, the address, the payment,
     * or anything the shopper did not search for. The wire carries a value, a
     * currency, an opaque reference, the ids of the items that came from a search,
     * and the term that led to them.
     *
     * GATED ON THE MERCHANT'S ANALYTICS CHOICE, not only on being connected. This is
     * usage data, and a merchant who declined to share it must not have their revenue
     * reported either — the toggle would otherwise mean less than it says.
     *
     * `occurred_at` IS PASSED THROUGH VERBATIM AND NEVER DERIVED HERE. It is half of
     * the key the service dedupes on, so a value recomputed at send time would land a
     * retry as a SECOND conversion row for the same order rather than colliding with
     * the first — the merchant's revenue counted twice, silently, and only on the
     * orders unlucky enough to need retrying. The caller stamps it once, at order
     * placement, and stores the literal string; see {@see \NitroSearch\Search\Model\OrderAttribution}.
     *
     * ⚠ IT RETURNS A TRI-STATE, NOT A BOOLEAN, AND THAT IS A CORRECTION MADE ON
     * 2026-08-10 RATHER THAN A PREFERENCE. Until this change the last thing this
     * method did was:
     *
     *     if (!$res['ok'] && $res['status'] >= 400 && $res['status'] < 500) {
     *         return true;   // "handled" — the caller then DELETES the report
     *     }
     *
     * with a comment reasoning that a 4xx is never worth retrying because the payload
     * is wrong or the shop is not entitled. That is exactly right for the refusals it
     * was written for — 400, 422, 403, 404 — and wrong for three the service actually
     * answers with, all three of them states a shop comes back from on its own:
     *
     *   429  throttled. POST /v1/orders accepts sixty reports a minute per store, so a
     *        shop bursting past one order a second loses EVERY order over the line.
     *        The busiest hour of the year reported the least revenue — and it is that
     *        hour's number a merchant reads when deciding whether search is worth
     *        paying for.
     *   409  the store is not verified YET. Verification happens out of band and
     *        usually lands minutes later; every order placed in that window was
     *        thrown away.
     *   423  the account is suspended. A billing state, and one merchants come back
     *        from within days.
     *
     * Each one cost exactly one order, permanently, with no error, no log line and no
     * row left anywhere to say a number was ever missing. 408 and 425 join the list
     * because both say "ask again" in so many words, and a proxy in front of a
     * merchant's own egress produces them as readily as the service does.
     *
     *   done   any 2xx (including the 202 that says {accepted:false,reason:'disabled'}),
     *          and every 4xx not named below — a payload the service refuses on its
     *          merits does not improve by being re-sent, and a poison report must
     *          never park itself at the head of the queue and block every later order
     *          behind it
     *   retry  401, 408, 409, 423, 425, 429, every 5xx, and a transport failure
     *          (which has no status at all and is reported as 0)
     *
     * 401 IS RETRYABLE HERE AND WOULD NOT BE ON A CALLER THAT CACHED ITS HEADERS.
     * {@see signed()} mints a fresh signature — and a fresh single-use nonce — inside
     * every attempt, so the next attempt is a genuinely different signed request
     * rather than a replay of the one just refused.
     *
     * @param array{order_id: int, value_cents: int, currency: string, occurred_at: string, item_ids?: array<int, string>, q?: string} $report
     *
     * @return array{done: bool, retry: bool, status: int, error: string}
     */
    public function reportOrder(array $report)
    {
        // NOT `done`, DELIBERATELY, AND THIS IS THE ONE PLACE THIS CONNECTOR PARTS
        // COMPANY WITH THE ONE WHOSE QUEUE IS ITS SCHEDULER. There a report that
        // cannot be sent is a job argument and dropping it is all that is on offer;
        // here it is a table row, and the states this branch tests are temporary —
        // credentials mid-reissue, a merchant who will switch sharing back on. Calling
        // it done would delete a queue of real revenue for a condition that clears,
        // which is the failure this whole method was rewritten to stop. The rows wait,
        // and age out on their own if the condition never does.
        if (!$this->settings->isConnected() || !$this->settings->get('SHARE_SEARCH_DATA', true)) {
            return array('done' => false, 'retry' => true, 'status' => 0, 'error' => 'not reporting');
        }

        $itemIds = array();
        foreach ((array) (isset($report['item_ids']) ? $report['item_ids'] : array()) as $id) {
            $itemIds[] = (string) $id;
        }

        $body = json_encode(array(
            'order_ref' => hash('sha256', $this->settings->installId() . '|order|' . (int) $report['order_id']),
            'value_cents' => (int) $report['value_cents'],
            'currency' => (string) $report['currency'],
            'occurred_at' => (string) $report['occurred_at'],
            'item_ids' => array_values($itemIds),
            'q' => (string) (isset($report['q']) ? $report['q'] : ''),
        ));

        $res = $this->signed('POST', '/v1/orders', $body, 10);

        $status = (int) $res['status'];
        $error = (string) $res['error'];

        if (!empty($res['ok'])) {
            return array('done' => true, 'retry' => false, 'status' => $status, 'error' => '');
        }

        if ($status === 0 || $status >= 500 || in_array($status, self::$orderRetryCodes, true)) {
            return array('done' => false, 'retry' => true, 'status' => $status, 'error' => $error);
        }

        return array('done' => true, 'retry' => false, 'status' => $status, 'error' => $error);
    }

    /**
     * Sign and send. Returns a uniform shape; never throws.
     *
     * @param string $method
     * @param string $path   must be the PATH ONLY — it is a signing input
     * @param string $body
     * @param int    $timeout
     *
     * @return array{ok: bool, status: int, json: mixed, body: string, error: string}
     */
    private function signed($method, $path, $body, $timeout = 20)
    {
        $headers = Hmac::headers(
            (string) $this->settings->get('SYNC_KEY_ID'),
            (string) $this->settings->get('SYNC_SECRET'),
            $method,
            $path,
            $body,
            (string) $this->settings->get('SITE_URL', $this->siteUrl),
            $this->settings->installId()
        );

        $lines = array('Accept: application/json');
        if ($body !== '') {
            $lines[] = 'Content-Type: application/json';
        }
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $res = $this->request($method, $this->settings->apiUrl() . $path, $lines, $body, $timeout);
        $res['json'] = json_decode($res['body'], true);

        // A transport error has a curl message; an HTTP error has a RESPONSE BODY and
        // no curl message at all, so `error` would be empty and the merchant would be
        // shown a bare "HTTP 422:" naming nothing. The service explains its refusals
        // in the body — surface it, bounded, rather than discarding the only thing
        // that says what is wrong.
        if (!$res['ok'] && $res['error'] === '' && $res['body'] !== '') {
            $res['error'] = self::explain($res['status'], $res['body']);
        }

        return $res;
    }

    /**
     * Turn a failed response into something worth showing a merchant.
     *
     * THE `message` FIELD, NOT THE WHOLE BODY. The service explains its refusals in
     * a JSON `message`, and that sentence is the entire useful content — but a body
     * can also carry a framework's debug payload with a file path, a line number and
     * a full stack trace, which is what a merchant saw here on the first 409: three
     * hundred characters of vendor paths in front of the four words that mattered.
     *
     * It falls back to the raw body rather than to a generic string, because a
     * response that is not our JSON at all — a proxy notice, a WAF interstitial, a
     * host's error page — is exactly the case where the raw text is the only clue,
     * and replacing it with "Connection failed" would throw away the diagnosis.
     *
     * @param int    $status
     * @param string $body
     *
     * @return string
     */
    private static function explain($status, $body)
    {
        $decoded = json_decode((string) $body, true);

        if (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message']) && $decoded['message'] !== '') {
            return 'HTTP ' . (int) $status . ': ' . $decoded['message'];
        }

        return 'HTTP ' . (int) $status . ': ' . substr((string) $body, 0, 500);
    }

    /**
     * One HTTP request via cURL.
     *
     * cURL rather than a stream wrapper because we need the status code, a bounded
     * timeout and control over the method — and because a shop with
     * `allow_url_fopen` off would otherwise be unable to sync at all.
     *
     * @param array<int, string> $headers
     *
     * @return array{ok: bool, status: int, body: string, error: string}
     */
    private function request($method, $url, array $headers, $body, $timeout)
    {
        if (!function_exists('curl_init')) {
            return array('ok' => false, 'status' => 0, 'body' => '', 'error' => 'PHP cURL extension is not available');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) $timeout);

        // ⚠ THE CONNECT TIMEOUT IS BOUNDED BY THE OVERALL ONE, and that matters
        // because some of these calls ride a shopper's page load on shops with no
        // cron. A flat 10s connect budget meant a five-second housekeeping call
        // could still hold a request open for ten — on hosts without
        // `fastcgi_finish_request` (mod_php, which a lot of OpenCart shops run) the
        // response has not been flushed at that point, so the shopper waits and an
        // Apache worker stays occupied. A blackholed egress rule is enough to do it,
        // and it costs nothing to be wrong about.
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, max(2, (int) $timeout)));
        // Redirects are NOT followed. A signed request's signature covers its path;
        // following a redirect would replay those headers at a different path, where
        // they are invalid — and would send the shop's credentials wherever the
        // redirect points.
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if ($body !== '' && $body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string) curl_error($ch);

        // ⚠ NO `curl_close($ch)`. It has done nothing since PHP 8.0 — the handle is
        // an object freed when `$ch` goes out of scope at the end of this method —
        // and PHP 8.5 DEPRECATED it, so calling it prints a notice on every single
        // request the module makes.
        //
        // That is not cosmetic here. Two of this module's endpoints answer with JSON
        // and nothing else, and the service treats a non-JSON body as a failed proof;
        // a notice printed ahead of the payload corrupts it. On PHP 7 the handle is
        // freed on scope exit just the same, so removing the call is safe on every
        // version either major runs on.

        if ($responseBody === false) {
            return array('ok' => false, 'status' => 0, 'body' => '', 'error' => $error !== '' ? $error : 'request failed');
        }

        return array(
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => (string) $responseBody,
            'error' => $error,
        );
    }

    /**
     * Persist a search block, defensively.
     *
     * The key gates the whole persist, and every other field falls back to its stored
     * value when absent — the widget has NO fallback for an empty engine host, so
     * blanking it would break storefront search even with a valid key.
     *
     * @param array<string, mixed> $search
     */
    private function storeSearch(array $search)
    {
        $key = isset($search['scoped_search_key']) ? (string) $search['scoped_search_key'] : '';
        if ($key === '') {
            return;
        }

        $update = array('SCOPED_SEARCH_KEY' => $key);

        foreach (array(
            'COLLECTION' => array('collection'),
            'ENGINE_HOST' => array('engine', 'host'),
            'SEARCH_PUBLIC_ID' => array('public_key_id'),
        ) as $setting => $path) {
            $value = self::pluck($search, $path);
            if ($value !== '') {
                $update[$setting] = $value;
            }
        }

        $this->settings->update($update);

        if (isset($search['widget']) && is_array($search['widget'])) {
            $this->storeWidget($search['widget']);
        }
        if (isset($search['events']) && is_array($search['events'])) {
            $this->storeEvents($search['events']);
        }
    }

    /**
     * Persist the usage-beacon endpoint and this shop's public token.
     *
     * ⚠ THE BLOCK ARRIVES AT TWO DIFFERENT DEPTHS, and that is why this was missed
     * entirely until the storefront widget needed it. `/v1/search-key` returns
     * `events` as a SIBLING of the search fields it also returns, so it is reached
     * through the same body this method's caller was handed; `/v1/connect` returns it
     * at the TOP LEVEL of a body whose search block is nested one deeper. Reading
     * only one of the two leaves the other silently unstored.
     *
     * WHAT IT COSTS TO GET WRONG IS INVISIBLE. Without these two values the widget
     * simply omits `cfg.events` and no beacon is ever sent — no error, no warning,
     * nothing on the Configure screen, just a store that reports no searches forever
     * while its storefront search works perfectly.
     *
     * BOTH OR NEITHER. A url without a token is an endpoint the beacon cannot
     * authenticate to, and a token without a url has nowhere to go; storing half of
     * a pair is how a partial response becomes a permanent broken state.
     *
     * @param array<string, mixed> $events
     */
    private function storeEvents(array $events)
    {
        $url = self::pluck($events, array('url'));
        $token = self::pluck($events, array('token'));

        if ($url === '' || $token === '') {
            return;
        }

        $this->settings->update(array('EVENTS_URL' => $url, 'EVENTS_TOKEN' => $token));
    }

    /**
     * @param array<string, mixed> $widget
     */
    private function storeWidget(array $widget)
    {
        $update = array();

        foreach (array('WIDGET_LOADER_URL' => 'loader_url', 'WIDGET_BUNDLE_URL' => 'bundle_url') as $setting => $key) {
            $value = self::pluck($widget, array($key));
            if ($value !== '') {
                $update[$setting] = $value;
            }
        }

        if (!empty($update)) {
            $this->settings->update($update);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string>   $path
     *
     * @return string
     */
    private static function pluck(array $data, array $path)
    {
        $cursor = $data;
        foreach ($path as $segment) {
            if (!is_array($cursor) || !isset($cursor[$segment])) {
                return '';
            }
            $cursor = $cursor[$segment];
        }

        return is_scalar($cursor) ? (string) $cursor : '';
    }
}
