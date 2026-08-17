<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

declare(strict_types=1);

namespace NitroSearch\Search\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Refuses an accent that is not a hex colour, and says so.
 *
 * WITHOUT THIS THE FIELD FAILS SILENTLY, which is worse than failing. Magento's
 * config form stores whatever text a merchant types; {@see \NitroSearch\Support\Design}
 * then drops anything that is not unambiguously a colour on the way out — correctly,
 * because these values are interpolated into CSS custom properties on a live
 * storefront and there is no legitimate input here needing `url(`, a semicolon or a
 * closing brace.
 *
 * So a merchant who types "blue" gets: a saved setting, a green success message, a
 * field that still shows "blue" on reload, and a storefront that ignores it. Nothing
 * anywhere says why. That is the shape of defect this project keeps finding late —
 * the value is assembled, stored, read, and thrown away, and every screen reports
 * success.
 *
 * THE VALIDATION IS DELIBERATELY NOT A SECOND COPY OF THE RULE. It calls the same
 * `Design::normalise()` the resolver and the OpenCart save path use, so "what counts
 * as a colour" has exactly one definition. A separate regex here would be a rule that
 * could drift from the one that actually decides.
 */
class Accent extends Value
{
    /**
     * @return $this
     *
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = (string) $this->getValue();

        // Empty means "use the default", which is a real choice and not an error.
        if (trim($value) === '') {
            return parent::beforeSave();
        }

        $clean = \NitroSearch\Support\Design::normalise('DESIGN_ACCENT', $value);

        if ($clean === null) {
            throw new LocalizedException(__(
                'The NitroSearch accent colour must be a hex colour such as #2563eb. '
                . '“%1” was not stored, because a value that is not a colour would be '
                . 'ignored on the storefront without telling you.',
                $value
            ));
        }

        // Stored NORMALISED — lowercase, with the hash — so the field shows on reload
        // exactly what the storefront will use. A field that displays `FDE047` while
        // the widget receives `#fde047` is a small lie that costs somebody an hour.
        $this->setValue($clean);

        return parent::beforeSave();
    }
}
