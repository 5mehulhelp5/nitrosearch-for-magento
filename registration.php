<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'NitroSearch_Search', __DIR__);

/*
 * THE VENDORED CORE NEEDS ITS OWN AUTOLOADER, AND ONLY ON ONE OF THE TWO INSTALL
 * PATHS. This is worth explaining, because it looks redundant next to composer.json.
 *
 * `lib/` holds the framework-free core this connector shares with the WooCommerce,
 * PrestaShop and OpenCart modules — `NitroSearch\Support\Hmac`,
 * `NitroSearch\Sync\Outbox` and friends, byte-identical across all four so that the
 * canonical signing string cannot drift between platforms. Those namespaces are
 * deliberately NOT under `NitroSearch\Search\`.
 *
 * That costs nothing on a Composer install: composer.json declares the extra PSR-4
 * prefixes and Composer generates a map that resolves them. But a module dropped into
 * `app/code` is autoloaded by Magento's CONVENTION — `Vendor\Module\` maps to
 * `app/code/Vendor/Module/` and nothing else — so every `NitroSearch\Support\…` class
 * is unresolvable there, and the failure is a fatal "class not found" deep inside a
 * controller rather than anything that points at autoloading.
 *
 * Registering the prefixes here makes both paths behave identically. It is guarded so
 * that on a Composer install, where Composer has already registered them, this is a
 * no-op rather than a second competing autoloader.
 *
 * The alternative — renaming the core's namespaces to `NitroSearch\Search\Support\…` —
 * was rejected: it would mean this module carries a MODIFIED copy of files whose whole
 * value is being unmodified. A signing input that differs by platform is the class of
 * bug the shared core exists to prevent.
 */
if (!class_exists(\NitroSearch\Support\Hmac::class, false)) {
    spl_autoload_register(static function (string $class): void {
        static $prefixes = [
            'NitroSearch\\Support\\' => __DIR__ . '/lib/Support/',
            'NitroSearch\\Sync\\' => __DIR__ . '/lib/Sync/',
            'NitroSearch\\Api\\' => __DIR__ . '/lib/Api/',
            'NitroSearch\\Storefront\\' => __DIR__ . '/lib/Storefront/',
        ];

        foreach ($prefixes as $prefix => $dir) {
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                continue;
            }

            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($file)) {
                require_once $file;
            }

            return;
        }

        // `NitroSearch\Settings` and `NitroSearch\SettingsStore` sit at the root of
        // lib/, so they are matched after the deeper prefixes above rather than by a
        // catch-all that would shadow them.
        if (strncmp($class, 'NitroSearch\\', 12) === 0 && strpos(substr($class, 12), '\\') === false) {
            $file = __DIR__ . '/lib/' . substr($class, 12) . '.php';

            if (is_file($file)) {
                require_once $file;
            }
        }
    });
}
