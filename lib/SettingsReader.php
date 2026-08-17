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
 * The read half of {@see Settings}, so a consumer that only reads can say so.
 *
 * IT EXISTS BECAUSE `Settings` IS `final` AND TAKES A `SettingsStore`. Anything
 * type-hinting the concrete class can only be exercised with Magento's whole
 * configuration stack behind it, and `tests/run.php` deliberately has none — the
 * runner's premise is that `lib/` is framework-free and testable without booting
 * Magento. Widening the hint to this interface is what makes the appearance layer
 * coverable at all.
 *
 * DELIBERATELY READ-ONLY. A collaborator handed this cannot write, which is the
 * honest shape for {@see Support\Design}: it resolves stored choices into widget
 * tokens and has no business persisting anything. Writing stays on `Settings`,
 * which is where the escaping and the cache invalidation live.
 */
interface SettingsReader
{
    /**
     * @param string $key     unprefixed, e.g. 'DESIGN_LOOK'
     * @param mixed  $default returned when the stored value is absent or empty
     *
     * @return mixed
     */
    public function get($key, $default = null);
}
