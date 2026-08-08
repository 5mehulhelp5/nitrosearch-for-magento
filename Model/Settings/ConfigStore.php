<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Model\Settings;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use NitroSearch\SettingsStore;

/**
 * `core_config_data`, through Magento's own configuration API.
 *
 * WHY NOT RAW SQL, which is what the OpenCart connector does. Three things come
 * free here and would all be lost by writing the table directly: values declared
 * with `backend_model="…\Config\Backend\Encrypted"` in `system.xml` are encrypted
 * with the store's crypt key on the way in and decrypted on the way out; the
 * configuration cache is invalidated on write; and scope resolution is handled
 * rather than reimplemented.
 *
 * SCOPE IS DEFAULT, ON PURPOSE, AND IT IS [D-055]. v1 indexes one store view, so
 * one set of credentials serves the install. Writing at default scope means a
 * merchant who later adds a second website does not silently inherit half a
 * configuration — they get the same one, and the admin screen tells them only one
 * store view is indexed. When multi-scope arrives, this class is where it lands,
 * and the `Settings` port above it does not change.
 */
class ConfigStore implements SettingsStore
{
    /**
     * Everything the module owns lives under this path. `purge()` deletes the
     * GROUP rather than a list of fields — see the interface's note on why.
     */
    private const GROUP = 'nitrosearch/credentials';

    private ScopeConfigInterface $scopeConfig;
    private WriterInterface $writer;
    private ReinitableConfigInterface $reinitable;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        WriterInterface $writer,
        ReinitableConfigInterface $reinitable
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->writer = $writer;
        $this->reinitable = $reinitable;
    }

    /**
     * @return array<string, string>
     */
    public function load(): array
    {
        $group = $this->scopeConfig->getValue(self::GROUP);

        if (!is_array($group)) {
            return [];
        }

        $out = [];
        foreach ($group as $field => $value) {
            if ($value === null) {
                continue;
            }

            // AN EMPTY XML ELEMENT PARSES AS AN ARRAY, NOT AN EMPTY STRING, and
            // skipping arrays silently dropped real settings. `<sync_key_id/>` in
            // config.xml becomes `[]` in the merged config tree; the previous version
            // of this loop treated that as "not a scalar, ignore" and the key never
            // reached `Settings` at all — so `isConnected()` answered false on a store
            // whose credentials were sitting in `core_config_data`.
            //
            // It was found by listing what the module could actually read and seeing
            // SYNC_SECRET present and SYNC_KEY_ID absent with both rows in the table.
            // `bin/magento config:show` is no help here: it validates against
            // system.xml and answers "path doesn't exist" for every credential,
            // whether or not the value loads.
            //
            // An empty element means "declared, no value", which is exactly the empty
            // string — so it is coerced rather than dropped. A NON-empty array would
            // be a nested group, which this module does not use and which has no
            // sensible string form, so that is still skipped.
            if (is_array($value)) {
                if ($value !== []) {
                    continue;
                }

                $value = '';
            }

            $out[strtoupper((string) $field)] = (string) $value;
        }

        return $out;
    }

    /**
     * @param array<string, string> $values
     */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->writer->save(self::GROUP . '/' . strtolower((string) $key), (string) $value);
        }

        // Without this the values just written are invisible to the rest of THIS
        // request — `ScopeConfigInterface` reads a cached snapshot taken at
        // bootstrap. The connect flow writes credentials and then immediately uses
        // them to sign a request, so a stale read here is not a cosmetic problem:
        // it signs with the previous key and gets a 401.
        $this->reinitable->reinit();
    }

    public function purge(): void
    {
        // BY GROUP, NEVER BY ENUMERATING FIELDS. `deleteConfig` on the group path
        // removes every row beneath it, including keys added by a version newer
        // than the one performing the uninstall — which is the property
        // `Settings::purge()` exists to provide.
        $this->writer->delete(self::GROUP);
        $this->reinitable->reinit();
    }
}
