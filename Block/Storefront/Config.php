<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Block\Storefront;

use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;
use NitroSearch\Settings;
use NitroSearch\Storefront\Widget;

/**
 * The `window.NitroSearchConfig` blob and the loader tag, in `head.additional`.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE FULL-PAGE-CACHE TRAP, WHICH IS NEW TO THIS PLATFORM.
 *
 * Every Magento store has a full page cache. WooCommerce, PrestaShop and OpenCart do
 * not, by default — so this problem has no prior art in the three shipped connectors
 * and no wrong implementation anywhere to falsify a right one.
 *
 * The scoped search key rotates every 86,400 seconds. Varnish's default TTL is *also*
 * 86,400 seconds. So a page cached at hour 0 can be served at hour 24 carrying a key
 * that has already expired, and the shopper gets a silent 401 from the engine on a
 * page that looks perfectly normal.
 *
 * **DO NOT REACH FOR `cacheable="false"`.** In `default.xml` — which is where this
 * block is added, because the widget belongs on every page — that attribute disables
 * the full page cache for *every page of the store*. It is the single most
 * destructive one-attribute mistake available in this module, it looks like a fix,
 * and the merchant experiences it as their whole site becoming slow.
 *
 * The mechanism instead is a CACHE TAG. {@see getIdentities()} returns a tag that
 * Magento writes into the page's `X-Magento-Tags`, so every page carrying this blob
 * is tagged with it. When the key is renewed, cleaning that one tag invalidates
 * exactly those pages — through the built-in FPC and through Varnish alike — and
 * nothing else. {@see \NitroSearch\Search\Model\CacheTag} does the cleaning, and it
 * is called from the paths that write a new key.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHAT IS ACTUALLY PROVEN, AND WHAT IS NOT. Measured on Magento 2.4.8, and the
 * answer is more interesting than "it works".
 *
 * PROVEN: pages really are cached here (`X-Magento-Cache-Debug` MISS then HIT), the
 * tag really is emitted (`nitrosearch_config_1` appears in `X-Magento-Tags` on every
 * page carrying the blob), and the blob is correctly absent when the store has no key.
 *
 * NOT PROVEN, AND IT COULD NOT BE STAGED: the actual failure — a cached page serving
 * a rotated-away key. **Every route to changing the key also evicts the page cache.**
 * `cache:clean config` was measured to turn a HIT into a MISS, and the production
 * path writes through Magento's config API, which invalidates the config cache the
 * same way. So on the BUILT-IN full page cache this trap appears to be largely
 * self-solving, provided the key is written through the config API rather than raw
 * SQL — which is exactly why `Model\Settings\ConfigStore` goes through the writer
 * instead of touching `core_config_data`.
 *
 * WHERE IT IS NOT SELF-SOLVING IS VARNISH, which cannot see a PHP cache call at all
 * and is purged only by the HTTP request `CacheTag` sends. **That is the case the
 * mechanism exists for, and this sandbox has no Varnish** — the rig ships none — so
 * the one scenario that genuinely needs this code is the one still untested.
 *
 * The service-side grace window that should accompany it — accepting the previous key
 * for a few hours — **also does not exist yet**. Until it does, a page cached in the
 * seconds before a rotation and served from an edge after it still carries a dead
 * key. Written down rather than left to be discovered.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * HALF-FORMED IS WORSE THAN ABSENT. {@see Widget::config()} returns null unless the
 * scoped key, engine host, collection and both asset urls are all present, and this
 * block renders nothing in that case. A store that is connected but not yet verified
 * has no key — a normal state, not an error — and emitting a partial blob would give
 * the loader something to fail on rather than nothing to do.
 */
class Config extends Template implements IdentityInterface
{
    /**
     * The tag prefix. Per store, because two store views can hold different keys and
     * invalidating one must not flush the other's pages.
     */
    public const CACHE_TAG = 'nitrosearch_config';

    private Settings $settings;
    private StoreManagerInterface $storeManager;

    public function __construct(
        Context $context,
        Settings $settings,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        $this->settings = $settings;
        $this->storeManager = $storeManager;
        parent::__construct($context, $data);
    }

    /**
     * What Magento tags this page with.
     *
     * @return string[]
     */
    public function getIdentities(): array
    {
        return [self::CACHE_TAG . '_' . $this->currentStoreId()];
    }

    /**
     * The config array, or null when this store is not ready to search.
     *
     * @return array<string, mixed>|null
     */
    public function getWidgetConfig(): ?array
    {
        $store = $this->_storeManager->getStore();

        $widget = new Widget(
            $this->settings,
            rtrim((string) $store->getBaseUrl(), '/'),
            (string) $store->getCurrentCurrencyCode(),
            (string) $this->_scopeConfig->getValue(
                'general/locale/code',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $store->getId()
            )
        );

        return $widget->config();
    }

    /**
     * JSON for the blob, escaped so it cannot break out of the script element.
     *
     * `JSON_HEX_TAG` turns `<` and `>` into `<` / `>`, which is what stops
     * a product name containing `</script>` from ending the script early and turning
     * a catalogue field into markup. `JSON_HEX_AMP` and the quote flags close the
     * same class of hole for attribute contexts. The other connectors carry a build
     * guard for exactly this, because it is invisible until a merchant names a
     * product something unfortunate.
     */
    public function getConfigJson(): string
    {
        $config = $this->getWidgetConfig();

        if ($config === null) {
            return '';
        }

        return (string) json_encode(
            $config,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );
    }

    public function getLoaderUrl(): string
    {
        return (string) $this->settings->get('WIDGET_LOADER_URL');
    }

    private function currentStoreId(): int
    {
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
