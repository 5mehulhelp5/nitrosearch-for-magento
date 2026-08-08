<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Model;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderInterface;
use NitroSearch\Api\Client;
use NitroSearch\Settings;

/**
 * Search → order attribution.
 *
 * When the widget adds to cart it marks the store's OWN cart request with
 * `ns_search=1` and `ns_q=<term>`. Magento is mid-request at that moment, so the
 * product is noted against the shopper's session. When an order is placed, the items
 * that came from a search make up the ATTRIBUTED SLICE — its value and a hashed order
 * reference are queued, and the existing heartbeat sends them.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHAT NEVER LEAVES THE STORE: the real order id (hashed with the install id first),
 * the customer, the address, the payment, the basket contents. The wire carries a
 * value, a currency, an opaque reference, the ids of items that came from a search,
 * and the term that led to them. Nothing about who bought them.
 *
 * TWO STORES, DELIBERATELY, because they have different lifetimes and different
 * failure modes:
 *
 *  - The **cart marker** lives in Magento's checkout session. It dies with the
 *    session, and losing one costs a single attribution nobody would notice.
 *  - The **pending report** is a table row, because it must survive: written during
 *    checkout, sent minutes later. Losing it loses revenue data that cannot be
 *    reconstructed from anything else.
 *
 * **REPORTING IS NEVER DONE DURING CHECKOUT.** It rides the heartbeat. A checkout
 * must never be slowed, and must certainly never fail, because a search API was
 * briefly unreachable — and an order-placement observer is precisely the wrong place
 * to open a socket. This is the same conclusion the PrestaShop connector reached, and
 * the reason is identical on both.
 *
 * GATED ON THE MERCHANT'S ANALYTICS CHOICE. A merchant who declined to share search
 * data has their revenue left alone too; the toggle would otherwise mean less than it
 * says on the screen.
 */
class OrderAttribution
{
    /** Attribution expires with the shopper's interest in it. */
    private const WINDOW_SECONDS = 604800;

    /**
     * Ceiling on marked products per session.
     *
     * Not a performance guard — a bound on how much a hostile or broken client can
     * push into a session it partly controls. A shopper with 25 search-driven items
     * in one basket is already unusual; one with 10,000 is not a shopper.
     */
    private const MAX_TRACKED = 25;

    /** Reports older than this are abandoned rather than sent. */
    private const REPORT_TTL_DAYS = 14;

    private const SESSION_KEY = 'nitrosearch_attribution';

    private ResourceConnection $resource;
    private CheckoutSession $checkoutSession;
    private Settings $settings;

    public function __construct(
        ResourceConnection $resource,
        CheckoutSession $checkoutSession,
        Settings $settings
    ) {
        $this->resource = $resource;
        $this->checkoutSession = $checkoutSession;
        $this->settings = $settings;
    }

    /**
     * Note that this product reached the cart from a search.
     *
     * Called from the add-to-cart request, which is the only moment the link between
     * a search term and a product exists — the cart itself has no memory of how
     * anything got into it.
     */
    public function markFromSearch(int $productId, string $query): void
    {
        if ($productId <= 0 || !$this->settings->get('SHARE_SEARCH_DATA', true)) {
            return;
        }

        $marker = $this->readMarker();

        $marker['ids'][$productId] = true;

        // The FIRST term wins, not the last. A shopper who searches "yoga", adds a
        // mat, then searches "towel" and adds one has two attributions to one order;
        // the term that opened the session is the one that earned it, and letting the
        // last write win would credit whichever search happened to be most recent.
        if (($marker['q'] ?? '') === '' && $query !== '') {
            $marker['q'] = mb_substr($query, 0, 128);
        }

        if (count($marker['ids']) > self::MAX_TRACKED) {
            $marker['ids'] = array_slice($marker['ids'], -self::MAX_TRACKED, null, true);
        }

        $marker['at'] = time();

        $this->checkoutSession->setData(self::SESSION_KEY, $marker);
    }

    /**
     * Queue an attributed report for a placed order.
     *
     * NEVER THROWS, and the catch is not defensive padding. This runs inside order
     * placement: an exception escaping here does not lose an attribution, it loses
     * the merchant a sale. Whatever goes wrong, the order must complete.
     */
    public function orderPlaced(OrderInterface $order): void
    {
        try {
            $this->queueReport($order);
        } catch (\Throwable $e) {
            // Deliberately silent. An attribution is worth nothing next to a checkout.
        }
    }

    private function queueReport(OrderInterface $order): void
    {
        if (!$this->settings->isConnected() || !$this->settings->get('SHARE_SEARCH_DATA', true)) {
            return;
        }

        $marker = $this->readMarker();

        if ($marker['ids'] === []) {
            return;
        }

        $attributedIds = [];
        $valueCents = 0;

        foreach ($order->getItems() as $item) {
            // PARENT ITEMS ONLY. A configurable order line appears twice — once as
            // the parent carrying the price the shopper paid, once as the simple
            // child carrying zero. Counting both double-counts the line; counting the
            // child alone attributes zero. This is Magento-specific and has no
            // analogue in the other connectors' carts.
            if ($item->getParentItemId()) {
                continue;
            }

            $productId = (int) $item->getProductId();

            if (!isset($marker['ids'][$productId])) {
                continue;
            }

            $attributedIds[] = $productId;

            // row_total_incl_tax after discount — what the shopper actually paid for
            // this line, which is what a revenue number has to mean. Integer minor
            // units, because the wire is minor units everywhere.
            $rowTotal = (float) ($item->getRowTotalInclTax() ?? $item->getRowTotal() ?? 0);
            $discount = (float) ($item->getDiscountAmount() ?? 0);
            $valueCents += (int) round(max(0.0, $rowTotal - $discount) * 100);
        }

        if ($attributedIds === []) {
            return;
        }

        $connection = $this->resource->getConnection();

        // insertOnDuplicate, not insert: the unique key on order_id means an order
        // placed twice by a retried request updates rather than throwing — and
        // throwing here would be caught and swallowed, silently losing the report.
        $connection->insertOnDuplicate(
            $this->resource->getTableName('nitrosearch_order_report'),
            [
                'order_id' => (int) $order->getEntityId(),
                'value_cents' => $valueCents,
                'currency' => (string) $order->getOrderCurrencyCode(),
                'item_ids' => implode(',', $attributedIds),
                'q' => (string) ($marker['q'] ?? ''),
                'occurred_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['value_cents', 'item_ids', 'q']
        );

        // The marker has done its job. Clearing it stops a second order in the same
        // session inheriting the first one's search term.
        $this->checkoutSession->unsetData(self::SESSION_KEY);
    }

    /**
     * Send queued reports. Called from the heartbeat, never from checkout.
     *
     * STOPS ON THE FIRST TRANSPORT FAILURE rather than burning through the queue
     * against a service that is plainly unreachable — the rows stay and the next
     * heartbeat tries again. A 4xx is treated as handled by the client, so a
     * malformed or unentitled report cannot park itself at the head of the queue and
     * block every later order behind it.
     */
    public function flush(int $limit = 10): int
    {
        if (!$this->settings->isConnected() || !$this->settings->get('SHARE_SEARCH_DATA', true)) {
            return 0;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('nitrosearch_order_report');

        $this->expireStale();

        $rows = $connection->fetchAll(
            $connection->select()->from($table)->order('id ASC')->limit($limit)
        );

        if ($rows === []) {
            return 0;
        }

        $client = new Client($this->settings, (string) $this->settings->get('SITE_URL'));
        $sent = 0;

        foreach ($rows as $row) {
            $ok = $client->reportOrder([
                'order_id' => (int) $row['order_id'],
                'value_cents' => (int) $row['value_cents'],
                'currency' => (string) $row['currency'],
                'occurred_at' => gmdate('c', strtotime((string) $row['occurred_at'])),
                'item_ids' => array_filter(explode(',', (string) $row['item_ids'])),
                'q' => (string) $row['q'],
            ]);

            if (!$ok) {
                break;
            }

            $connection->delete($table, ['id = ?' => (int) $row['id']]);
            $sent++;
        }

        return $sent;
    }

    /**
     * Abandon reports too old to be worth sending.
     *
     * Without this, a store disconnected for a month reconnects and floods the
     * service with stale revenue events — and analytics that arrive weeks late are
     * worse than absent, because they silently move a number somebody has already
     * read and acted on.
     */
    private function expireStale(): void
    {
        $connection = $this->resource->getConnection();

        $connection->delete(
            $this->resource->getTableName('nitrosearch_order_report'),
            ['occurred_at < ?' => gmdate('Y-m-d H:i:s', time() - (self::REPORT_TTL_DAYS * 86400))]
        );
    }

    /**
     * @return array{ids: array<int, bool>, q: string, at: int}
     */
    private function readMarker(): array
    {
        $marker = $this->checkoutSession->getData(self::SESSION_KEY);

        if (!is_array($marker) || !isset($marker['ids']) || !is_array($marker['ids'])) {
            return ['ids' => [], 'q' => '', 'at' => 0];
        }

        // Expired markers are dropped rather than used. A basket abandoned for a week
        // and completed later did not come from that search in any sense a merchant
        // would recognise.
        if ((time() - (int) ($marker['at'] ?? 0)) > self::WINDOW_SECONDS) {
            return ['ids' => [], 'q' => '', 'at' => 0];
        }

        return [
            'ids' => $marker['ids'],
            'q' => (string) ($marker['q'] ?? ''),
            'at' => (int) ($marker['at'] ?? 0),
        ];
    }
}
