<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Model;

use Magento\CacheInvalidate\Model\PurgeCache;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\PageCache\Model\Cache\Type as PageCacheType;
use Magento\Store\Model\StoreManagerInterface;
use NitroSearch\Search\Block\Storefront\Config;

/**
 * Invalidates the cached pages carrying a stale search key.
 *
 * THE OTHER HALF OF THE FULL-PAGE-CACHE FIX. `Block\Storefront\Config` tags every
 * page carrying the config blob; this clears that tag when the key changes, so those
 * pages — and only those pages — re-render with the new key.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE FIRST VERSION OF THIS CLASS DID NOT WORK, AND IT LOOKED LIKE IT DID.
 *
 * It injected `Magento\Framework\App\CacheInterface` and called `clean($tags)`. That
 * is the DEFAULT cache, and the full page cache is a different instance —
 * `Magento\PageCache\Model\Cache\Type`. Cleaning tags on the default cache does not
 * touch a cached page, so the command reported success, the tag looked correct in
 * `X-Magento-Tags`, and the storefront kept serving the old key.
 *
 * It was caught by running the sequence end to end: prime the cache, rotate the key,
 * serve (stale — the trap is real), invalidate, serve again (still stale — the fix
 * was not). Without that fifth step the whole mechanism would have shipped looking
 * finished, because every visible signal short of it was green. This is the test
 * docs called one of the two most important on this platform, and it earned that.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * CLEANS BY TAG, NEVER FLUSHES. `cache:flush` on a busy store is a thundering herd
 * against the merchant's own PHP workers. This touches only the pages that are
 * actually wrong, which matters most on exactly the stores where being wrong matters
 * most.
 *
 * TWO CACHES, BECAUSE A MERCHANT MAY HAVE EITHER OR BOTH. The built-in FPC is cleaned
 * directly. Varnish cannot see a PHP cache call at all and is purged by an HTTP
 * request instead, which is what `PurgeCache` sends. A store on Varnish with only the
 * first call would keep serving the dead key from the edge while the origin was
 * perfectly correct — the same shape as a CDN purge that named the wrong files and
 * reported green.
 */
class CacheTag
{
    private PageCacheType $pageCache;
    private TypeListInterface $cacheTypeList;
    private StoreManagerInterface $storeManager;
    private ?PurgeCache $purgeCache;

    public function __construct(
        PageCacheType $pageCache,
        TypeListInterface $cacheTypeList,
        StoreManagerInterface $storeManager,
        ?PurgeCache $purgeCache = null
    ) {
        $this->pageCache = $pageCache;
        $this->cacheTypeList = $cacheTypeList;
        $this->storeManager = $storeManager;
        $this->purgeCache = $purgeCache;
    }

    /**
     * Invalidate every cached page carrying our config blob.
     *
     * Cleans the tag for EVERY store view rather than only the indexed one. The tag
     * is per store because two views can hold different keys, but a key rotation is
     * an install-level event: the cost of over-cleaning is a handful of pages
     * re-rendering, against the cost of under-cleaning, which is a shopper getting a
     * search box that silently returns nothing.
     */
    public function invalidate(): void
    {
        $tags = [];

        foreach ($this->storeManager->getStores(true) as $store) {
            $tags[] = Config::CACHE_TAG . '_' . (int) $store->getId();
        }

        if ($tags === []) {
            return;
        }

        // The built-in full page cache. MATCHING_ANY_TAG, not MATCHING_TAG: the
        // latter requires a page to carry ALL the given tags, and a page carries
        // exactly one of ours.
        $this->pageCache->clean(\Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG, $tags);

        // Varnish and other external caches, which cannot see the call above.
        // Optional because `Magento_CacheInvalidate` can be disabled, and a store
        // without it is a store with no external cache to purge.
        if ($this->purgeCache !== null) {
            $this->purgeCache->sendPurgeRequest($tags);
        }

        // Marks the cache type invalidated in the admin, so a merchant who has
        // disabled automatic invalidation still sees that something needs refreshing
        // rather than silently serving stale pages.
        $this->cacheTypeList->invalidate('full_page');
    }
}
