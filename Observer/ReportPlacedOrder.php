<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use NitroSearch\Search\Model\OrderAttribution;

/**
 * Queues an attributed report when an order is saved.
 *
 * QUEUES, DOES NOT SEND. This runs inside the checkout transaction, and an outbound
 * HTTP request there would put our network latency — and our downtime — directly into
 * the merchant's conversion funnel. The heartbeat sends it minutes later, and nothing
 * about a revenue number needs to be immediate.
 *
 * `sales_order_save_after` RATHER THAN THE SUCCESS-PAGE CONTROLLER, because a shopper
 * who closes the tab before the success page still bought something. Tying attribution
 * to a page view would quietly under-report every order placed by anyone with an
 * unreliable connection — which is a biased sample, not a small one.
 *
 * `sales_order_save_after` RATHER THAN `sales_order_place_after`, because `place()`
 * runs before the order row exists and hands out an entity id of 0 — see the note in
 * `etc/events.xml`, which is where the whole failure is written down. It fires again
 * on every later save, and that is harmless: `OrderAttribution` clears the marker when
 * it queues, and no marker means no report.
 *
 * REGISTERED AT GLOBAL SCOPE, not frontend. Luma's one-page checkout places orders
 * through the REST API, in the `webapi_rest` area, where a frontend-scoped observer
 * does not exist.
 */
class ReportPlacedOrder implements ObserverInterface
{
    private OrderAttribution $attribution;

    public function __construct(OrderAttribution $attribution)
    {
        $this->attribution = $attribution;
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');

        if ($order === null) {
            return;
        }

        // OrderAttribution::orderPlaced() swallows everything internally. An
        // attribution is worth nothing next to a completed checkout.
        $this->attribution->orderPlaced($order);
    }
}
