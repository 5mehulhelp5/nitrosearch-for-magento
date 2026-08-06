<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

namespace NitroSearch\Support;

/**
 * This shop's STOREFRONT base url, as the service will see it.
 *
 * ⚠ NOT `config_url`. That is what this module asked for first, by analogy with
 * other platforms that keep the shop URL in a settings row — and on a stock
 * OpenCart install of either major there is no such setting at all. It came back
 * empty, the service answered `422 site_url is required`, and connect failed with
 * a message that was accurate but described a symptom two steps downstream.
 *
 * The URL lives in `config.php` as a CONSTANT, and which constant depends on which
 * application is running:
 *
 *   admin/config.php     HTTP_CATALOG   the storefront, seen from the back office
 *   catalog/config.php   HTTP_SERVER    the storefront, seen from itself
 *
 * So both are consulted, storefront-first. `HTTPS_CATALOG` is preferred when it
 * exists — and it does NOT always exist: OpenCart 4's installer writes it only
 * under some configurations, and OpenCart 3's writes it always. Every read is
 * therefore guarded by `defined()`, because an undefined constant is a fatal on
 * PHP 8 rather than the notice it used to be.
 *
 * A trailing slash is stripped: the service stores this value and appends paths
 * to it, and `https://shop.example//index.php` is not the same URL.
 */
final class ShopUrl
{
    /**
     * @return string empty when no constant is defined, which the caller must treat
     *                as "cannot connect yet" rather than as a usable value
     */
    public static function resolve()
    {
        // Storefront-facing first (admin context), then self-referential (catalog
        // context). HTTPS before HTTP in each pair: a shop with SSL on must not
        // announce an http:// url the service would then fetch and be redirected from.
        foreach (array('HTTPS_CATALOG', 'HTTP_CATALOG', 'HTTPS_SERVER', 'HTTP_SERVER') as $constant) {
            if (!defined($constant)) {
                continue;
            }

            $value = (string) constant($constant);
            if ($value === '') {
                continue;
            }

            // In the ADMIN application HTTP_SERVER points at `…/admin/`, which is not
            // the storefront and must never be announced as one. It is only reached
            // here when neither CATALOG constant exists, i.e. the catalog app, where
            // it is correct — but strip a trailing `/admin/` anyway rather than trust
            // that reasoning to survive a merchant's custom admin directory name.
            $value = preg_replace('#/admin[^/]*/?$#', '', rtrim($value, '/'));

            return rtrim((string) $value, '/');
        }

        return '';
    }
}
