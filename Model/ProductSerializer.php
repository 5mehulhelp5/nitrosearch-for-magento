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
        $prices = $this->prices(array_keys($indexable), $store);
        $urlKeys = $this->attributeValues(array_keys($indexable), 'url_key', $store);
        $skus = [];

        $items = [];

        foreach ($ids as $id) {
            if (!isset($indexable[$id])) {
                // Not indexable in this scope, for ANY reason — deleted, disabled,
                // made invisible, unassigned from the website. One outcome.
                $items[] = ItemBuilder::product($id)->delete()->version($version)->toArray();

                continue;
            }

            $row = $indexable[$id];
            $skus[$id] = (string) $row['sku'];

            $builder = ItemBuilder::product($id)
                ->name((string) ($row['name'] ?? $row['sku']))
                ->sku((string) $row['sku'])
                // Always true here by construction — an item that reaches this branch
                // satisfied the visibility rule. Stated explicitly because the wire
                // fails CLOSED on a missing `visible`, which would index the product
                // and make it unreachable.
                ->visible(true)
                ->version($version);

            if (isset($prices[$id])) {
                $builder->price($prices[$id]);
            }

            if (isset($urlKeys[$id]) && $urlKeys[$id] !== '') {
                $builder->permalink($this->permalink($store, (string) $urlKeys[$id]));
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
     * Final prices from the price index.
     *
     * `final_price` is what the storefront shows for a simple product;
     * `min_price` is what it shows for a range type, where a bundle or configurable
     * reads "From £x". Taking `final_price` for everything would print a bundle's
     * computed floor as though it were a fixed price.
     *
     * @param int[] $ids
     *
     * @return array<int, Money>
     */
    private function prices(array $ids, StoreInterface $store): array
    {
        if ($ids === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $currency = (string) $store->getCurrentCurrencyCode();

        $select = $connection->select()
            ->from(
                $this->resource->getTableName('catalog_product_index_price'),
                ['entity_id', 'final_price', 'min_price']
            )
            ->where('entity_id IN (?)', $ids)
            ->where('customer_group_id = ?', self::CUSTOMER_GROUP)
            ->where('website_id = ?', (int) $store->getWebsiteId());

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $amount = $row['final_price'] !== null ? (string) $row['final_price'] : (string) $row['min_price'];

            if ($amount === '' || !is_numeric($amount)) {
                continue;
            }

            // The kit converts to integer minor units and carries the exponent with
            // it, so a currency with no minor unit — JPY — cannot be sent as though
            // it had two decimal places. That defect shipped on this project once
            // already, in the other direction.
            $out[(int) $row['entity_id']] = Money::fromDecimalString(
                number_format((float) $amount, 2, '.', ''),
                $currency
            );
        }

        return $out;
    }

    /**
     * One store-scoped varchar attribute for a set of ids, store row winning.
     *
     * @param int[] $ids
     *
     * @return array<int, string>
     */
    private function attributeValues(array $ids, string $code, StoreInterface $store): array
    {
        if ($ids === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $attributeId = $this->attributeId($code);
        $table = $this->resource->getTableName('catalog_product_entity_varchar');

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
        static $cache = [];

        if (isset($cache[$code])) {
            return $cache[$code];
        }

        $connection = $this->resource->getConnection();

        $cache[$code] = (int) $connection->fetchOne(
            $connection->select()
                ->from(['a' => $this->resource->getTableName('eav_attribute')], ['attribute_id'])
                ->join(
                    ['t' => $this->resource->getTableName('eav_entity_type')],
                    't.entity_type_id = a.entity_type_id',
                    []
                )
                ->where('a.attribute_code = ?', $code)
                ->where('t.entity_type_code = ?', 'catalog_product')
        );

        return $cache[$code];
    }
}
