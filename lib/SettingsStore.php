<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

namespace NitroSearch;

/**
 * Where {@see Settings} actually keeps its values.
 *
 * DELIBERATELY THREE METHODS. The temptation is a richer port — typed getters, a
 * scope argument, per-key encryption hints — and every one of those would be a
 * guess about a second implementation that does not exist yet. There is exactly
 * one implementation today (`NitroSearch\Search\Model\Settings\ConfigStore`, over
 * Magento's `core_config_data`), so anything beyond what `Settings` already calls
 * would be describing that implementation rather than abstracting it.
 *
 * `purge()` IS NOT `save([])`, AND THE DIFFERENCE MATTERS. `Settings::purge()`
 * exists so that uninstalling an older version still removes values a NEWER one
 * introduced. An implementation that enumerates the keys it knows about would
 * leave the rest behind — so it must delete by whatever prefix or group scopes
 * the module, never by walking a list.
 */
interface SettingsStore
{
    /**
     * Every value this module owns. Keys without the module's own prefix, values
     * as strings. An absent store returns an empty array, never null.
     *
     * @return array<string, string>
     */
    public function load();

    /**
     * Persist a map of key => value. Keys arrive upper-cased; the implementation
     * owns whatever prefixing or casing its backing store needs.
     *
     * @param array<string, string> $values
     */
    public function save(array $values);

    /** Remove everything this module owns — by scope, not by enumerating keys. */
    public function purge();
}
