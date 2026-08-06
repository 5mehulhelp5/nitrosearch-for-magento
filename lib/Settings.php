<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

namespace NitroSearch;

/**
 * Every persistent value the module owns, behind one accessor.
 *
 * STORED IN `oc_setting`, WHICH IS THE ONE THING BOTH MAJORS AGREE ON. OpenCart 3
 * and 4 differ in directory layout, class naming, base classes, routing and type
 * declarations — but `oc_setting` is column-for-column identical in both
 * (`setting_id, store_id, code, key, value, serialized`), and so is the `query()`
 * / `escape()` surface of their DB library. So this file is shared verbatim and
 * takes OpenCart's own `$this->db` rather than wrapping it.
 *
 * `store_id = 0` DELIBERATELY. OpenCart multi-store puts each storefront in its
 * own row; this module indexes one catalogue and holds one set of credentials, so
 * it writes the default-store row and says so on the Configure screen rather than
 * pretending to a multi-store support it does not have. Surfacing a limit beats
 * silently applying one shop's settings to another's.
 */
final class Settings
{
    /** The `code` column value for every row this module owns. */
    const CODE = 'module_nitrosearch';

    /** @var SettingsStore where the values actually live */
    private $store;

    /** @var array<string, mixed>|null read-through cache for this request */
    private $cache = null;

    /**
     * Keys holding a credential. Listed explicitly because they must be cleared
     * on disconnect and must never be rendered into an admin template.
     *
     * @var array<int, string>
     */
    private static $secrets = array('SYNC_SECRET', 'SCOPED_SEARCH_KEY', 'EVENTS_TOKEN', 'CONNECT_TOKEN');

    /**
     * Defaults for everything the module reads. A key absent from here is a typo
     * rather than a feature — get() would silently return '' forever.
     *
     * @var array<string, mixed>
     */
    private static $defaults = array(
        'API_URL' => 'https://api.nitrosearch.io',
        'CONNECT_TOKEN' => '',
        'CONNECTED' => false,
        'VERIFIED' => false,
        'CLAIMED' => false,
        'INSTALL_ID' => '',
        'SITE_URL' => '',
        'STORE_ID' => '',
        'COLLECTION' => '',
        'SYNC_KEY_ID' => '',
        'SYNC_SECRET' => '',
        'SEARCH_PUBLIC_ID' => '',
        'SCOPED_SEARCH_KEY' => '',
        'ENGINE_HOST' => '',
        'WIDGET_LOADER_URL' => '',
        'WIDGET_BUNDLE_URL' => '',
        'EVENTS_URL' => '',
        'EVENTS_TOKEN' => '',
        // The merchant's opt-out for the anonymous usage beacon. DEFAULTS TO TRUE,
        // matching the other connectors, so an existing shop is not silently
        // switched off by an update — but it must be switchable, and until this key
        // existed it was not. The module sent no `analytics` key at all, the widget
        // opts out only on an explicit `cfg.analytics === false`, and the service
        // issues the events token to every verified store, so there was no layer at
        // which an OpenCart merchant could decline. The other two connectors had
        // this from their first release.
        'SHARE_SEARCH_DATA' => true,
        'PRODUCT_LIMIT' => 0,
        'PRODUCT_COUNT' => 0,
        'AT_LIMIT' => false,
        'PLAN' => '',
        'LAST_SYNC' => '',
        'LAST_ERROR' => '',
        'DRAIN_TOKEN' => '',
        'DRAIN_RAN_AT' => 0,
        // The unattended heartbeat's own clock, kept separate from DRAIN_RAN_AT on
        // purpose: the drain stamp is claimed by every page-load tick whether or not
        // there was anything to send, so sharing it would let a busy storefront
        // starve the status poll indefinitely.
        'STATUS_CHECKED_AT' => 0,
        // The search-key refresh's own clock, separate from the poll's. They are two
        // jobs on two intervals: `/v1/status` cannot deliver a key, so a shop that
        // only ever polls holds the key it was issued at onboarding until it expires.
        'CONFIG_REFRESHED_AT' => 0,
        // The last re-sync request this shop has already acted on. Recorded so a
        // confirmation that fails to arrive costs one retry rather than a second full
        // walk of the catalogue.
        'RESYNC_TOKEN_DONE' => '',
        // The full-walk cursor. Kept here rather than in its own table because it is
        // a handful of scalars that must survive exactly as long as the install.
        'FULLSYNC_ACTIVE' => false,
        'FULLSYNC_CURSOR' => 0,
        'FULLSYNC_TOTAL' => 0,
        'FULLSYNC_STARTED' => '',
        'FULLSYNC_DONE' => '',
    );

    /**
     * STORAGE IS INJECTED, which is the one real difference from the other
     * connectors and is deliberate.
     *
     * OpenCart's copy of this class takes OpenCart's own `$db` and writes raw SQL
     * against `oc_setting` — a documented exception to that module's own
     * framework-free rule, tolerable there because both OpenCart majors share the
     * schema. Magento has a first-class configuration API with encryption, scope
     * and cache invalidation built in, and reaching past it to write
     * `core_config_data` directly would lose all three. So the port is the
     * narrowest thing that both worlds can honour: load everything, save a map.
     *
     * @param SettingsStore $store
     */
    public function __construct(SettingsStore $store)
    {
        $this->store = $store;
    }

    /**
     * @param string $key     unprefixed, e.g. 'SYNC_SECRET'
     * @param mixed  $default
     *
     * @return mixed
     */
    public function get($key, $default = null)
    {
        $all = $this->all();

        if (isset($all[$key]) && $all[$key] !== '') {
            return $all[$key];
        }

        if ($default !== null) {
            return $default;
        }

        return isset(self::$defaults[$key]) ? self::$defaults[$key] : '';
    }

    /**
     * Write one or more values. Booleans are stored as '1'/'0' so a round trip
     * through `text` cannot turn false into the string 'false', which is truthy.
     *
     * @param array<string, mixed> $values
     */
    public function update(array $values)
    {
        $normalised = array();

        foreach ($values as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            $normalised[strtoupper($key)] = (string) $value;
        }

        $this->store->save($normalised);

        $this->cache = null;
    }

    /**
     * Every value this module owns, read once per request.
     *
     * @return array<string, mixed>
     */
    public function all()
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $out = array();

        foreach ($this->store->load() as $key => $value) {
            $out[strtoupper($key)] = $value;
        }

        $this->cache = $out;

        return $out;
    }

    /**
     * A stable per-install identifier, minted once.
     *
     * It is a SIGNING INPUT and an outbound identifier, so it must not be derived
     * from anything discoverable: a value built from the shop URL or the database
     * name would be reproducible by anyone who knows the shop.
     *
     * @return string
     */
    public function installId()
    {
        $id = (string) $this->get('INSTALL_ID');

        if ($id === '') {
            $id = bin2hex(random_bytes(16));
            $this->update(array('INSTALL_ID' => $id));
        }

        return $id;
    }

    /**
     * The cron endpoint's shared secret, minted once and kept for the life of the
     * install.
     *
     * MINTED LAZILY HERE RATHER THAN ONLY AT INSTALL, and that is a fix rather than a
     * convenience. `install()` minted it and `disconnect()` purged it — so a merchant
     * who disconnected and reconnected kept a cron job pointed at a url that answered
     * `403 forbidden` from then on, forever, with nothing anywhere saying why. Their
     * catalogue simply stopped syncing.
     *
     * IT MUST ALSO SURVIVE A DISCONNECT, which is why {@see \NitroSearch\Admin\Actions::disconnect()}
     * carries it across alongside the install id. Re-minting would heal the 403 and
     * still break them: the token is IN the url they gave their host's scheduler, and
     * a new one makes that url wrong instead of unauthorised.
     *
     * NEVER DERIVED from the shop url or the install id — both are discoverable, and
     * a guessable token turns this endpoint into an unauthenticated way to make a
     * merchant's own server do unbounded work.
     *
     * @return string
     */
    public function drainToken()
    {
        $token = (string) $this->get('DRAIN_TOKEN');

        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            $this->update(array('DRAIN_TOKEN' => $token));
        }

        return $token;
    }

    /**
     * @return bool
     */
    public function isConnected()
    {
        return (string) $this->get('SYNC_SECRET') !== '' && (string) $this->get('SYNC_KEY_ID') !== '';
    }

    /**
     * @return string
     */
    public function apiUrl()
    {
        return rtrim((string) $this->get('API_URL'), '/');
    }

    /**
     * Everything except the credentials — safe to render into an admin template.
     *
     * @return array<string, mixed>
     */
    public function publicValues()
    {
        $all = $this->all();

        foreach (self::$secrets as $secret) {
            unset($all[$secret]);
        }

        return $all;
    }

    /**
     * Remove every row this module owns. Used by uninstall, and by disconnect —
     * which is why it is keyed on `code` rather than enumerating keys: a value
     * added in a later version must not survive an uninstall from an earlier one.
     */
    public function purge()
    {
        $this->store->purge();

        $this->cache = null;
    }
}
