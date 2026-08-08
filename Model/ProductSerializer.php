<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Model;

use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use NitroSearch\AdapterKit\ItemBuilder;
use NitroSearch\AdapterKit\Money;

/**
 * Turns product ids into wire items.
 *
 * TWO RULES DECIDE EVERYTHING HERE, and both are decisions rather than mechanics.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * 1. WHAT IS INDEXED — and therefore what the merchant is billed for.
 *
 * A product is indexable when it is **enabled**, **assigned to the indexed scope's
 * website**, and carries **`visibility` in {IN_SEARCH, BOTH}**. Nothing about the
 * product TYPE appears in that rule, which is the point.
 *
 * Magento has five product types and three of them are composite, so the obvious
 * approach — a rule per type — needs a paragraph to explain and a table to predict.
 * Visibility is Magento's own answer to "is this in the search index", the merchant
 * already sets it, and it adapts in both directions: a configurable's children
 * default to NOT_VISIBLE and are neither indexed nor billed, while a bundle
 * selection the merchant deliberately published as separately findable is both.
 *
 * MEASURED on Magento's own sample catalogue rather than assumed: 2,040 product rows
 * reduce to **181 billable**. 1,847 configurable children are hidden; the one grouped
 * product's three children are hidden too — so the common belief that grouped members
 * are ordinarily visible is false on Magento's own reference data — and exactly two
 * bundle selections really are `visibility = 4`, which is what proves the rule is not
 * vacuous in the other direction.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * 2. RESOLVE, DO NOT CLASSIFY.
 *
 * The changelog hands us bare ids and no operation. A deleted product's row is
 * already gone; a product that merely became invisible still has one; both must leave
 * the index. Rather than deciding "upsert or delete?" at enqueue time — when the
 * answer is unknowable — this asks one question per id: *does it resolve to something
 * indexable in this scope?* Yes → upsert. No, for any reason → delete.
 *
 * One code path, automatically correct for delete, disable, unpublish, visibility
 * change and website unassignment alike. Adding a branch per cause is how a sync
 * develops holes that only appear on someone else's catalogue.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * PRICE COMES FROM THE PRICE INDEX, NEVER FROM RAW ATTRIBUTES.
 *
 * `catalog_product_index_price` already accounts for special price, tier price,
 * catalog price rules, customer group and website, and supplies `min_price`/
 * `max_price` for the range types. Reading `price` off the EAV table instead would
 * show a shopper a number their own storefront disagrees with — for a bundle or a
 * configurable it would often show nothing at all.
 *
 * **v1 indexes the NOT_LOGGED_IN customer group (0) and says so.** The price index is
 * dimensioned by customer group; a store whose group prices differ will show
 * logged-in shoppers a different number than the index carries. That is a real
 * limitation, declared rather than discovered.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * EVERY FIELD THE OTHER THREE CONNECTORS SEND, BECAUSE THE FIRST VERSION SENT SIX.
 *
 * This class shipped emitting `id, name, sku, visible, price, currency,
 * price_exponent, permalink` and nothing else. That reads as a thin-but-working
 * first cut, and it was not: **`in_stock` is absent, and the wire treats absent as
 * OUT OF STOCK.** So every Magento product indexed out of stock, the widget's
 * results grid renders "Out of stock" and refuses to offer Add to cart on an item
 * it believes is unbuyable — and the search → cart → order attribution path, the
 * thing this connector is sold on, could never run once. It was not "untested";
 * it was unreachable. Found by loading the storefront and reading the panel, which
 * is the only place any of it is visible.
 *
 * The rest were quieter and no less real: no `image`, so every result rendered a
 * grey placeholder; no `description`, `categories` or `brand`, so search matched on
 * the product NAME alone and the facet rail had nothing to show; no `variants`, so
 * a configurable looked like a simple product and offered a cart button that could
 * only ever redirect.
 *
 * All of them are one SQL join each, against tables this class was already reading.
 * They stay SQL, for the reason the indexability predicate is SQL.
 */
class ProductSerializer
{
    /** Customer group 0 — NOT_LOGGED_IN. See the class note on prices. */
    private const CUSTOMER_GROUP = 0;

    private ResourceConnection $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>> wire items, one per id, in id order
     */
    public function serialize(array $ids, StoreInterface $store, int $version): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === []) {
            return [];
        }

        $indexable = $this->indexableRows($ids, $store);
        $indexableIds = array_keys($indexable);
        $skus = array_map(static fn ($row) => (string) $row['sku'], $indexable);

        $priceRows = $this->priceRows($indexableIds, $store);
        $urlKeys = $this->attributeValues($indexableIds, 'url_key', $store);
        $images = $this->images($indexableIds, $store);
        $descriptions = $this->descriptions($indexableIds, $store);
        $brands = $this->brands($indexableIds, $store);
        $categories = $this->categoryNames($indexableIds, $store);
        $inStock = $this->stockStatuses($indexableIds, $skus, $store);
        $variants = $this->variants($indexableIds, $store);

        $currency = (string) $store->getCurrentCurrencyCode();

        $items = [];

        foreach ($ids as $id) {
            if (!isset($indexable[$id])) {
                // Not indexable in this scope, for ANY reason — deleted, disabled,
                // made invisible, unassigned from the website. One outcome.
                $items[] = ItemBuilder::product($id)->delete()->version($version)->toArray();

                continue;
            }

            $row = $indexable[$id];

            $builder = ItemBuilder::product($id)
                ->name((string) ($row['name'] ?? $row['sku']))
                ->sku((string) $row['sku'])
                // Always true here by construction — an item that reaches this branch
                // satisfied the visibility rule. Stated explicitly because the wire
                // fails CLOSED on a missing `visible`, which would index the product
                // and make it unreachable.
                ->visible(true)
                // ALWAYS EMITTED, never conditional. The wire reads an absent
                // `in_stock` as OUT OF STOCK, which is the right default for a
                // producer that cannot answer and the wrong one for a producer that
                // simply forgot to ask — and forgetting is what shipped here. An
                // unresolvable id defaults to IN stock rather than out: a product
                // with no stock row is not a product a shopper should be told they
                // cannot buy.
                ->inStock($inStock[$id] ?? true)
                ->version($version);

            if (isset($priceRows[$id])) {
                $price = $this->money($priceRows[$id], (string) $row['type_id'], $currency);

                if ($price !== null) {
                    $builder->price($price);
                }

                // A discount is the index disagreeing with itself: `price` is what
                // the product costs, `final_price` what it costs today. Both are
                // computed by Magento, so a catalog price rule, a special price and
                // a tier price all read the same way and none needs its own branch.
                if ($this->onSale($priceRows[$id])) {
                    $builder->onSale(true);
                }
            }

            if (isset($urlKeys[$id]) && $urlKeys[$id] !== '') {
                $builder->permalink($this->permalink($store, (string) $urlKeys[$id]));
            }

            if (isset($images[$id]) && $images[$id] !== '') {
                $builder->image($images[$id]);
            }

            if (isset($descriptions[$id]) && $descriptions[$id] !== '') {
                $builder->description($descriptions[$id]);
            }

            if (isset($brands[$id]) && $brands[$id] !== '') {
                $builder->brand($brands[$id]);
            }

            if (!empty($categories[$id])) {
                $builder->categories($categories[$id]);
            }

            foreach ($variants[$id] ?? [] as $variant) {
                $builder->variant(
                    $variant['id'],
                    $variant['sku'],
                    $variant['price'],
                    $variant['in_stock'],
                    $variant['attributes']
                );
            }

            $items[] = $builder->toArray();
        }

        return $items;
    }

    /**
     * The indexability predicate, as one query.
     *
     * Deliberately a JOIN rather than loading product models. A batch of 100 product
     * models is 100 EAV hydrations and a measurable share of a merchant's cron
     * window; this is one round trip. The other connectors learned the same lesson
     * from the other direction — their apply path spent most of its time in
     * hydration rather than in the database.
     *
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>> keyed by entity id
     */
    private function indexableRows(array $ids, StoreInterface $store): array
    {
        $connection = $this->resource->getConnection();

        $statusAttr = $this->attributeId('status');
        $visibilityAttr = $this->attributeId('visibility');
        $nameAttr = $this->attributeId('name');

        $select = $connection->select()
            ->from(['e' => $this->resource->getTableName('catalog_product_entity')], ['entity_id', 'sku', 'type_id'])
            // Website assignment: the scope half of the predicate.
            ->join(
                ['w' => $this->resource->getTableName('catalog_product_website')],
                'w.product_id = e.entity_id AND w.website_id = ' . (int) $store->getWebsiteId(),
                []
            )
            ->columns(['name' => 'IFNULL(name_store.value, name_default.value)'])
            ->where('e.entity_id IN (?)', $ids);

        // Status and visibility are store-scoped EAV ints: a product can be enabled
        // globally and disabled for one store view, and the store-view row WINS.
        // Reading only the default row would index products a shopper cannot reach.
        foreach ([['status', $statusAttr], ['visibility', $visibilityAttr]] as [$alias, $attributeId]) {
            $select->joinLeft(
                [$alias . '_default' => $this->resource->getTableName('catalog_product_entity_int')],
                "{$alias}_default.entity_id = e.entity_id AND {$alias}_default.attribute_id = {$attributeId} AND {$alias}_default.store_id = 0",
                []
            )->joinLeft(
                [$alias . '_store' => $this->resource->getTableName('catalog_product_entity_int')],
                "{$alias}_store.entity_id = e.entity_id AND {$alias}_store.attribute_id = {$attributeId} AND {$alias}_store.store_id = " . (int) $store->getId(),
                []
            );
        }

        $select->joinLeft(
            ['name_default' => $this->resource->getTableName('catalog_product_entity_varchar')],
            "name_default.entity_id = e.entity_id AND name_default.attribute_id = {$nameAttr} AND name_default.store_id = 0",
            []
        )->joinLeft(
            ['name_store' => $this->resource->getTableName('catalog_product_entity_varchar')],
            "name_store.entity_id = e.entity_id AND name_store.attribute_id = {$nameAttr} AND name_store.store_id = " . (int) $store->getId(),
            []
        );

        $select->where('IFNULL(status_store.value, status_default.value) = ?', 1)
            ->where('IFNULL(visibility_store.value, visibility_default.value) IN (?)', [
                Visibility::VISIBILITY_IN_SEARCH,
                Visibility::VISIBILITY_BOTH,
            ]);

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $out[(int) $row['entity_id']] = $row;
        }

        return $out;
    }

    /**
     * Raw price-index rows, keyed by entity id.
     *
     * `final_price` is what the storefront shows for a simple product;
     * `min_price` is what it shows for a range type, where a bundle or configurable
     * reads "From £x". Taking `final_price` for everything would print a bundle's
     * computed floor as though it were a fixed price.
     *
     * The whole row is kept rather than a Money, because `price` versus
     * `final_price` is also the on-sale answer and re-deriving it would mean a
     * second query for a column already in hand.
     *
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>>
     */
    private function priceRows(array $ids, StoreInterface $store): array
    {
        if ($ids === []) {
            return [];
        }

        $connection = $this->resource->getConnection();

        $select = $connection->select()
            ->from(
                $this->resource->getTableName('catalog_product_index_price'),
                ['entity_id', 'price', 'final_price', 'min_price']
            )
            ->where('entity_id IN (?)', $ids)
            ->where('customer_group_id = ?', self::CUSTOMER_GROUP)
            ->where('website_id = ?', (int) $store->getWebsiteId());

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $out[(int) $row['entity_id']] = $row;
        }

        return $out;
    }

    /**
     * The shopper-facing price from a price-index row.
     *
     * WHICH COLUMN IS THE HEADLINE PRICE IS A PRODUCT-TYPE QUESTION, and this is the
     * measurement rather than the reasoning — every claim below was read off the
     * sandbox's own rendered pages:
     *
     *   simple 24-WB01   price 32  final 32  min 32   → storefront "$32.00"
     *   simple on sale   price 32  final 24  min 24   → "Special Price $24.00"
     *   configurable 737 price 35  final 28  min 21   → "As low as $28.00"
     *   grouped 46       price ␀   final ␀   min 14   → "Starting at $14.00"
     *   BUNDLE 45        price 0   final 0   min 61   → "From $61.00 To $77.00"
     *
     * So `final_price` is the headline everywhere it exists — **except on a bundle,
     * where it is 0** because the bundle itself carries no price, and except on a
     * grouped product, where it is NULL. `min_price` is not a general substitute: on
     * the configurable it is 21, a TIER price for a quantity nobody is buying, and
     * printing it would undercut the store's own listing by $7 on every result.
     *
     * The previous rule was `final_price ?? min_price`, which took 0 for the bundle.
     * It never showed because nothing else the bundle needed — image, stock,
     * description — was being sent either.
     *
     * @param array<string, mixed> $row
     */
    private function money(array $row, string $type, string $currency): ?Money
    {
        $amount = $row['final_price'] !== null ? (string) $row['final_price'] : (string) $row['min_price'];

        if ($type === 'bundle' || (is_numeric($amount) && (float) $amount <= 0.0)) {
            $amount = (string) $row['min_price'];
        }

        if ($amount === '' || !is_numeric($amount)) {
            return null;
        }

        // The kit converts to integer minor units and carries the exponent with
        // it, so a currency with no minor unit — JPY — cannot be sent as though
        // it had two decimal places. That defect shipped on this project once
        // already, in the other direction.
        return Money::fromDecimalString(
            number_format((float) $amount, 2, '.', ''),
            $currency
        );
    }

    /** @param array<string, mixed> $row */
    private function onSale(array $row): bool
    {
        if ($row['price'] === null || $row['final_price'] === null) {
            return false;
        }

        // A tenth of a minor unit, so floating-point noise in a DECIMAL(12,4)
        // column cannot put a sale badge on a product that is not discounted.
        return (float) $row['final_price'] < ((float) $row['price'] - 0.001);
    }

    /**
     * Stock status, MSI-aware, with the legacy index as the fallback.
     *
     * THE TWO SOURCES ARE THE SAME TABLE ON A DEFAULT INSTALL AND DIVERGE ON A REAL
     * ONE. `inventory_stock_1` is literally a VIEW over `cataloginventory_stock_status`
     * (confirmed with SHOW CREATE VIEW on the sandbox), so a single-source merchant
     * gets the same answer either way. A merchant with custom stocks gets
     * `inventory_stock_<id>` — a real table, keyed by SKU, that the legacy one knows
     * nothing about. Reading only the legacy table would quietly report the default
     * stock's answer to a store that does not use it.
     *
     * Resolved through `inventory_stock_sales_channel` rather than through MSI's PHP
     * API, so this class keeps its no-hydration rule and, more importantly, cannot
     * fatal on a store where the MSI modules are disabled: the table simply is not
     * there, and the legacy path answers.
     *
     * @param int[]              $ids
     * @param array<int, string> $skus keyed by entity id
     *
     * @return array<int, bool>
     */
    private function stockStatuses(array $ids, array $skus, StoreInterface $store): array
    {
        if ($ids === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $stockTable = $this->msiStockTable($store);

        if ($stockTable !== null) {
            $wanted = array_values(array_intersect_key($skus, array_flip($ids)));

            if ($wanted === []) {
                return [];
            }

            $rows = $connection->fetchPairs(
                $connection->select()
                    ->from($stockTable, ['sku', 'is_salable'])
                    ->where('sku IN (?)', $wanted)
            );

            $out = [];
            foreach ($ids as $id) {
                $sku = $skus[$id] ?? null;

                if ($sku !== null && array_key_exists($sku, $rows)) {
                    $out[$id] = (bool) (int) $rows[$sku];
                }
            }

            return $out;
        }

        // MAX, not the first row: `cataloginventory_stock_status` is keyed by
        // (product, website, stock) and a product salable in any of them is salable.
        $select = $connection->select()
            ->from($this->resource->getTableName('cataloginventory_stock_status'), [
                'product_id',
                'in_stock' => 'MAX(stock_status)',
            ])
            ->where('product_id IN (?)', $ids)
            ->group('product_id');

        return array_map(
            static fn ($value) => (bool) (int) $value,
            $connection->fetchPairs($select)
        );
    }

    /**
     * The MSI stock table for this store's website, or null when MSI is not in play.
     *
     * Returns null for the DEFAULT stock too: its table is a view over the legacy
     * one, so the fallback path is not merely equivalent, it is the same rows
     * without the SKU join.
     */
    private function msiStockTable(StoreInterface $store): ?string
    {
        $connection = $this->resource->getConnection();
        $channel = $this->resource->getTableName('inventory_stock_sales_channel');

        if (!$connection->isTableExists($channel)) {
            return null;
        }

        $websiteCode = '';

        try {
            $websiteCode = (string) $store->getWebsite()->getCode();
        } catch (\Throwable $e) {
            // A store detached from its website is not a case to guess at.
            return null;
        }

        if ($websiteCode === '') {
            return null;
        }

        $stockId = (int) $connection->fetchOne(
            $connection->select()
                ->from($channel, ['stock_id'])
                ->where('type = ?', 'website')
                ->where('code = ?', $websiteCode)
                ->limit(1)
        );

        // 1 is Magento's default stock. Its "table" is the legacy view.
        if ($stockId <= 1) {
            return null;
        }

        $table = $this->resource->getTableName('inventory_stock_' . $stockId);

        return $connection->isTableExists($table) ? $table : null;
    }

    /**
     * Absolute thumbnail URLs.
     *
     * `small_image` is the attribute Magento's own listing pages use; `image` is the
     * full-size fallback for a product that has only that. The value is a path
     * relative to `catalog/product` inside the media directory.
     *
     * THE ORIGINAL FILE, NOT THE RESIZED CACHE ENTRY. Magento's resized URLs are
     * produced by the image helper from a hydrated product model — the thing this
     * class exists to avoid — and the cache entry may not exist until something asks
     * for it, so a cache URL can 404 for a product nobody has viewed. The other
     * connectors send originals too.
     *
     * @param int[] $ids
     *
     * @return array<int, string>
     */
    private function images(array $ids, StoreInterface $store): array
    {
        if ($ids === []) {
            return [];
        }

        $small = $this->attributeValues($ids, 'small_image', $store);
        $full = $this->attributeValues($ids, 'image', $store);

        // The media base comes from the store rather than from core_config_data:
        // an install can pin it in app/etc/env.php, where a direct table read would
        // not see it — which is exactly how this sandbox is configured.
        $mediaBase = rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/');

        $out = [];

        foreach ($ids as $id) {
            $path = (string) ($small[$id] ?? '');

            if ($path === '' || $path === 'no_selection') {
                $path = (string) ($full[$id] ?? '');
            }

            if ($path === '' || $path === 'no_selection') {
                continue;
            }

            $out[$id] = $mediaBase . '/catalog/product/' . ltrim($path, '/');
        }

        return $out;
    }

    /**
     * Plain-text descriptions, short one first.
     *
     * Same rule and the same 1,000-character cap as the PrestaShop connector, which
     * is precedent rather than invention: the field is indexed and never displayed,
     * so a 40 KB Magento description would cost engine memory on every store for
     * matches nobody reads.
     *
     * @param int[] $ids
     *
     * @return array<int, string>
     */
    private function descriptions(array $ids, StoreInterface $store): array
    {
        if ($ids === []) {
            return [];
        }

        $short = $this->attributeValues($ids, 'short_description', $store, 'catalog_product_entity_text');
        $long = $this->attributeValues($ids, 'description', $store, 'catalog_product_entity_text');

        $out = [];

        foreach ($ids as $id) {
            $text = $this->plainText((string) ($short[$id] ?? ''));

            if ($text === '') {
                $text = $this->plainText((string) ($long[$id] ?? ''));
            }

            if ($text !== '') {
                $out[$id] = mb_substr($text, 0, 1000);
            }
        }

        return $out;
    }

    /**
     * HTML to something worth indexing.
     *
     * The tag is replaced by a SPACE rather than removed, because Magento's editor
     * emits `<li>Cotton</li><li>Machine wash</li>` with no whitespace between them
     * and `strip_tags` alone would index "CottonMachine wash" — one token that
     * matches neither word.
     */
    private function plainText(string $html): string
    {
        $text = strip_tags(str_replace(['<', '>'], [' <', '> '], $html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Brand, from `manufacturer` when the merchant uses it.
     *
     * Magento Open Source ships `manufacturer` as an optional select and no store
     * has to populate it — Magento's own sample data does not, measured. So this is
     * absent-by-default rather than empty-by-mistake, and the caller emits nothing
     * when it resolves to nothing.
     *
     * @param int[] $ids
     *
     * @return array<int, string>
     */
    private function brands(array $ids, StoreInterface $store): array
    {
        if ($ids === []) {
            return [];
        }

        $attributeId = $this->attributeId('manufacturer');

        if ($attributeId === 0) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $intTable = $this->resource->getTableName('catalog_product_entity_int');
        $optionValue = $this->resource->getTableName('eav_attribute_option_value');

        $select = $connection->select()
            ->from(['d' => $intTable], ['entity_id'])
            // The option LABEL, store row winning over the admin one, because a
            // brand facet showing an option id is worse than no facet.
            ->columns(['label' => 'IFNULL(ov_store.value, ov_default.value)'])
            ->joinLeft(
                ['s' => $intTable],
                's.entity_id = d.entity_id AND s.attribute_id = d.attribute_id AND s.store_id = ' . (int) $store->getId(),
                []
            )
            ->joinLeft(
                ['ov_default' => $optionValue],
                'ov_default.option_id = IFNULL(s.value, d.value) AND ov_default.store_id = 0',
                []
            )
            ->joinLeft(
                ['ov_store' => $optionValue],
                'ov_store.option_id = IFNULL(s.value, d.value) AND ov_store.store_id = ' . (int) $store->getId(),
                []
            )
            ->where('d.entity_id IN (?)', $ids)
            ->where('d.attribute_id = ?', $attributeId)
            ->where('d.store_id = ?', 0);

        return array_filter(array_map('strval', $connection->fetchPairs($select)));
    }

    /**
     * Category names per product, store-scoped.
     *
     * ROOTS ARE EXCLUDED. Every Magento product is in the tree's root and in the
     * store's own root ("Default Category" on a stock install), so including them
     * would give every product in the catalogue the same two categories — a facet
     * with one value, which is a facet that tells a shopper nothing.
     *
     * @param int[] $ids
     *
     * @return array<int, array<int, string>>
     */
    private function categoryNames(array $ids, StoreInterface $store): array
    {
        if ($ids === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $nameAttr = $this->categoryAttributeId('name');

        if ($nameAttr === 0) {
            return [];
        }

        $varchar = $this->resource->getTableName('catalog_category_entity_varchar');

        $select = $connection->select()
            ->from(['cp' => $this->resource->getTableName('catalog_category_product')], ['product_id'])
            ->columns(['name' => 'IFNULL(n_store.value, n_default.value)'])
            ->joinLeft(
                ['n_default' => $varchar],
                "n_default.entity_id = cp.category_id AND n_default.attribute_id = {$nameAttr} AND n_default.store_id = 0",
                []
            )
            ->joinLeft(
                ['n_store' => $varchar],
                "n_store.entity_id = cp.category_id AND n_store.attribute_id = {$nameAttr} AND n_store.store_id = " . (int) $store->getId(),
                []
            )
            ->where('cp.product_id IN (?)', $ids)
            ->where('cp.category_id NOT IN (?)', [
                \Magento\Catalog\Model\Category::TREE_ROOT_ID,
                (int) $store->getRootCategoryId(),
            ])
            ->order('cp.position ASC');

        $out = [];

        foreach ($connection->fetchAll($select) as $row) {
            $name = trim((string) $row['name']);

            if ($name === '') {
                continue;
            }

            $productId = (int) $row['product_id'];

            if (!in_array($name, $out[$productId] ?? [], true)) {
                $out[$productId][] = $name;
            }
        }

        return $out;
    }

    /**
     * Configurable children as variants of their parent.
     *
     * WHY THIS IS NOT OPTIONAL POLISH. Without variants a configurable arrives
     * looking like a simple product, so the widget offers Add to cart — and Magento
     * refuses a configurable added without its options and answers a redirect. The
     * shopper gets bounced to the product page by a button that promised otherwise.
     * With variants the widget renders "View", which is what the theme's own listing
     * does, and the variation SKUs and their option values become searchable and
     * facetable ([D-028]: still ONE document and ONE unit of quota, however many
     * children).
     *
     * Grouped and bundle children are deliberately NOT variants. A grouped member is
     * bought as itself and a bundle selection is a choice within a purchase; neither
     * is "the same product in another size", and Magento's own cart treats them that
     * way too. The one that is separately findable is already indexed in its own
     * right by the visibility rule.
     *
     * @param int[] $ids
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function variants(array $ids, StoreInterface $store): array
    {
        if ($ids === []) {
            return [];
        }

        $connection = $this->resource->getConnection();

        $links = $connection->fetchAll(
            $connection->select()
                ->from(['l' => $this->resource->getTableName('catalog_product_super_link')], ['parent_id', 'product_id'])
                ->join(
                    ['e' => $this->resource->getTableName('catalog_product_entity')],
                    'e.entity_id = l.product_id',
                    ['sku']
                )
                ->where('l.parent_id IN (?)', $ids)
        );

        if ($links === []) {
            return [];
        }

        $childIds = array_values(array_unique(array_map(static fn ($r) => (int) $r['product_id'], $links)));

        $childPrices = $this->priceRows($childIds, $store);
        $childSkus = [];
        foreach ($links as $link) {
            $childSkus[(int) $link['product_id']] = (string) $link['sku'];
        }
        $childStock = $this->stockStatuses($childIds, $childSkus, $store);
        $childOptions = $this->superAttributeValues($ids, $childIds, $store);

        $currency = (string) $store->getCurrentCurrencyCode();

        $out = [];

        foreach ($links as $link) {
            $parentId = (int) $link['parent_id'];
            $childId = (int) $link['product_id'];

            if (!isset($childPrices[$childId])) {
                // No price row means the child is not salable in this scope. Sending
                // it would widen the parent's price range with a number no shopper
                // can be charged.
                continue;
            }

            // A configurable's children are simple products by construction, so the
            // bundle branch cannot apply to them.
            $price = $this->money($childPrices[$childId], 'simple', $currency);

            if ($price === null) {
                continue;
            }

            $out[$parentId][] = [
                'id' => $childId,
                'sku' => (string) $link['sku'],
                'price' => $price,
                'in_stock' => $childStock[$childId] ?? true,
                'attributes' => $childOptions[$childId] ?? [],
            ];
        }

        return $out;
    }

    /**
     * The option LABELS of each child's configurable attributes, keyed by child id.
     *
     * Labels, not ids — an attribute facet reading "93" instead of "Blue" is worse
     * than no facet at all, and the wire says attribute VALUES are not normalised,
     * so what is sent is what a shopper sees.
     *
     * @param int[] $parentIds
     * @param int[] $childIds
     *
     * @return array<int, array<string, array<int, string>>>
     */
    private function superAttributeValues(array $parentIds, array $childIds, StoreInterface $store): array
    {
        if ($parentIds === [] || $childIds === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $intTable = $this->resource->getTableName('catalog_product_entity_int');
        $optionValue = $this->resource->getTableName('eav_attribute_option_value');
        $labelTable = $this->resource->getTableName('eav_attribute_label');

        $select = $connection->select()
            ->from(['sa' => $this->resource->getTableName('catalog_product_super_attribute')], [])
            ->join(
                ['a' => $this->resource->getTableName('eav_attribute')],
                'a.attribute_id = sa.attribute_id',
                []
            )
            ->join(
                ['v' => $intTable],
                'v.attribute_id = sa.attribute_id AND v.store_id = 0',
                ['entity_id']
            )
            // The attribute's own store label when it has one, otherwise its admin
            // frontend label, otherwise the code — a facet must always have a name.
            ->joinLeft(
                ['al' => $labelTable],
                'al.attribute_id = sa.attribute_id AND al.store_id = ' . (int) $store->getId(),
                []
            )
            ->joinLeft(
                ['ov_default' => $optionValue],
                'ov_default.option_id = v.value AND ov_default.store_id = 0',
                []
            )
            ->joinLeft(
                ['ov_store' => $optionValue],
                'ov_store.option_id = v.value AND ov_store.store_id = ' . (int) $store->getId(),
                []
            )
            ->columns([
                'label' => 'IFNULL(NULLIF(al.value, \'\'), IFNULL(NULLIF(a.frontend_label, \'\'), a.attribute_code))',
                'value' => 'IFNULL(ov_store.value, ov_default.value)',
            ])
            ->where('sa.product_id IN (?)', $parentIds)
            ->where('v.entity_id IN (?)', $childIds);

        $out = [];

        foreach ($connection->fetchAll($select) as $row) {
            $value = trim((string) $row['value']);
            $label = trim((string) $row['label']);

            if ($value === '' || $label === '') {
                continue;
            }

            $childId = (int) $row['entity_id'];

            if (!in_array($value, $out[$childId][$label] ?? [], true)) {
                $out[$childId][$label][] = $value;
            }
        }

        return $out;
    }

    /**
     * One store-scoped attribute for a set of ids, store row winning.
     *
     * The table is a parameter because Magento splits EAV by backend type and the
     * fields this class needs are not all varchar: `description` and
     * `short_description` live in `catalog_product_entity_text`. Defaulting to
     * varchar keeps every existing caller unchanged.
     *
     * @param int[] $ids
     *
     * @return array<int, string>
     */
    private function attributeValues(
        array $ids,
        string $code,
        StoreInterface $store,
        string $table = 'catalog_product_entity_varchar'
    ): array {
        if ($ids === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $attributeId = $this->attributeId($code);

        if ($attributeId === 0) {
            return [];
        }

        $table = $this->resource->getTableName($table);

        $select = $connection->select()
            ->from(['d' => $table], ['entity_id'])
            ->columns(['value' => 'IFNULL(s.value, d.value)'])
            ->joinLeft(
                ['s' => $table],
                's.entity_id = d.entity_id AND s.attribute_id = d.attribute_id AND s.store_id = ' . (int) $store->getId(),
                []
            )
            ->where('d.entity_id IN (?)', $ids)
            ->where('d.attribute_id = ?', $attributeId)
            ->where('d.store_id = ?', 0);

        return array_map('strval', $connection->fetchPairs($select));
    }

    /**
     * The shopper-facing URL.
     *
     * Built from the store's own base URL and the product's `url_key` plus the
     * configured suffix, rather than from `Product::getProductUrl()`, because that
     * requires a hydrated model — the thing this class exists to avoid — and emits
     * the URL for whichever store happens to be current rather than the one being
     * indexed.
     */
    private function permalink(StoreInterface $store, string $urlKey): string
    {
        $suffix = (string) $this->resource->getConnection()->fetchOne(
            $this->resource->getConnection()->select()
                ->from($this->resource->getTableName('core_config_data'), ['value'])
                ->where('path = ?', 'catalog/seo/product_url_suffix')
                ->limit(1)
        );

        if ($suffix === '') {
            $suffix = '.html';
        }

        return rtrim((string) $store->getBaseUrl(), '/') . '/' . $urlKey . $suffix;
    }

    /** EAV attribute ids, resolved once per request. */
    private function attributeId(string $code): int
    {
        return $this->eavAttributeId('catalog_product', $code);
    }

    /** The same, for the category entity — `name` lives on a different type. */
    private function categoryAttributeId(string $code): int
    {
        return $this->eavAttributeId('catalog_category', $code);
    }

    /**
     * Zero when the attribute does not exist, and every caller must treat it as
     * "this store does not have that field" rather than as an id. `manufacturer`
     * is removable, and a query on `attribute_id = 0` matches nothing quietly.
     */
    private function eavAttributeId(string $entityType, string $code): int
    {
        static $cache = [];

        $key = $entityType . ':' . $code;

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $connection = $this->resource->getConnection();

        $cache[$key] = (int) $connection->fetchOne(
            $connection->select()
                ->from(['a' => $this->resource->getTableName('eav_attribute')], ['attribute_id'])
                ->join(
                    ['t' => $this->resource->getTableName('eav_entity_type')],
                    't.entity_type_id = a.entity_type_id',
                    []
                )
                ->where('a.attribute_code = ?', $code)
                ->where('t.entity_type_code = ?', $entityType)
        );

        return $cache[$key];
    }
}
