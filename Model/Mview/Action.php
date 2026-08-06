<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Model\Mview;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Mview\ActionInterface;

/**
 * What Magento hands our changelog to, once a minute.
 *
 * TRIVIAL BY DESIGN, AND IT MUST STAY THAT WAY. This runs inside the merchant's
 * `indexer_update_all_views` cron, in the same process as every other view. It
 * enqueues ids and returns. It does NOT open a socket, sign a request, serialise a
 * product or talk to NitroSearch — `Cron\Drain` does all of that, on its own
 * schedule, where a slow or unreachable API delays only us.
 *
 * A network call here would put our latency inside a core cron job on someone
 * else's server. That is the kind of thing that gets an extension uninstalled.
 *
 * THE ONE PIECE OF REAL LOGIC IS THE FAN-OUT, and it is not optional. Mview hands
 * us bare entity ids from twelve tables, and three of those tables key CHILD
 * products: `catalog_product_super_link` (configurable children),
 * `catalog_product_bundle_selection` (bundle selections) and `catalog_product_link`
 * (grouped members). A child's price changing is precisely when the PARENT's
 * indexed document is wrong — and the parent's own row was never touched, so
 * nothing else will notice. Core's fulltext view subscribes to those same tables
 * for the same reason.
 *
 * WE DO NOT CLASSIFY THE OPERATION HERE. It is tempting to decide "upsert or
 * delete?" while we have the id in hand, and it would be wrong: at this moment a
 * deleted product's row is already gone, and a product that merely became invisible
 * still has one. Both must leave the index, and the two are indistinguishable
 * without asking a question this class should not be asking on a cron tick. The
 * drain resolves it later — "does this id resolve to something indexable in the
 * indexed scope?" — which is one code path that is automatically correct for
 * delete, disable, unpublish, visibility change and website unassignment alike.
 */
class Action implements ActionInterface
{
    private ResourceConnection $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * @param int[] $ids entity ids from our changelog, already de-duplicated by Mview
     */
    public function execute($ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));

        if ($ids === []) {
            return;
        }

        $ids = $this->withParents($ids);

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('nitrosearch_outbox');

        // ms-epoch, the same last-write-wins clock the other three connectors use.
        // It is the version the service compares, so two modules racing on one row
        // resolve to the later write rather than to whichever arrived last.
        $version = (int) round(microtime(true) * 1000);

        $rows = [];
        foreach ($ids as $id) {
            $rows[] = [
                'object_type' => 'product',
                'object_id' => $id,
                'op' => 'upsert',
                'version' => $version,
                'status' => 'pending',
                // [D-055]: carried from day one even though v1 indexes one store
                // view, so multi-scope is a backend change rather than a module
                // rewrite. Zero means "the configured view", resolved at drain time.
                'store_id' => 0,
            ];
        }

        // ON DUPLICATE KEY: an id already waiting is not enqueued twice, it has its
        // version bumped. Without this a bulk import would write one row per write
        // per product and the outbox would grow with the import rather than with the
        // catalogue.
        $connection->insertOnDuplicate($table, $rows, ['op', 'version', 'status']);
    }

    /**
     * Add the parents of any id that is a composite child.
     *
     * Three separate tables because Magento models the three composite types with
     * three different link mechanisms, and none of them is reachable from the
     * others. `catalog_product_link` additionally carries related/upsell/crosssell
     * links, so it is filtered to the GROUPED link type — without that filter a
     * price change on any product would re-enqueue everything that merely
     * cross-sells it, which is a fan-out that grows with the merchant's
     * merchandising rather than with their catalogue.
     *
     * @param int[] $ids
     *
     * @return int[]
     */
    private function withParents(array $ids): array
    {
        $connection = $this->resource->getConnection();
        $parents = [];

        $super = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('catalog_product_super_link'), ['parent_id'])
                ->where('product_id IN (?)', $ids)
        );

        $bundle = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('catalog_product_bundle_selection'), ['parent_product_id'])
                ->where('product_id IN (?)', $ids)
        );

        // Link type 3 is GROUPED. The constant lives in Magento\GroupedProduct, which
        // this module does not depend on — a merchant can disable that module — so the
        // value is inlined with its meaning stated rather than pulled through a
        // dependency that may not be there.
        $grouped = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('catalog_product_link'), ['product_id'])
                ->where('linked_product_id IN (?)', $ids)
                ->where('link_type_id = ?', 3)
        );

        foreach ([$super, $bundle, $grouped] as $set) {
            foreach ($set as $parentId) {
                $parents[] = (int) $parentId;
            }
        }

        return array_values(array_unique(array_merge($ids, array_filter($parents))));
    }
}
