<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Observer;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use NitroSearch\Search\Model\OrderAttribution;

/**
 * Catches `ns_search` / `ns_q` off the add-to-cart request.
 *
 * THIS IS THE ONLY MOMENT THE LINK EXISTS. A cart has no memory of how anything got
 * into it — by the time an order is placed, the fact that a product came from a
 * search is gone unless something recorded it during the request that added it.
 *
 * `checkout_cart_add_product_complete` FIRES AFTER THE PRODUCT IS ACTUALLY IN THE
 * CART, which matters: the pre-dispatch event fires for adds that then fail
 * validation (a required option not chosen, a product out of stock), and marking
 * those would attribute revenue to items the shopper never bought.
 *
 * READS THE REQUEST, NOT THE REFERRER. The markers are posted by the widget on the
 * same request that adds the item — no cross-request state, no referrer sniffing, and
 * nothing that survives a cache.
 */
class CaptureSearchAdd implements ObserverInterface
{
    private RequestInterface $request;
    private OrderAttribution $attribution;

    public function __construct(RequestInterface $request, OrderAttribution $attribution)
    {
        $this->request = $request;
        $this->attribution = $attribution;
    }

    public function execute(Observer $observer): void
    {
        // NEVER THROWS. This is inside a shopper's add-to-cart. An exception here
        // would turn a working "add to basket" into an error page for the sake of an
        // analytics marker.
        try {
            if ((string) $this->request->getParam('ns_search') !== '1') {
                return;
            }

            $product = $observer->getEvent()->getData('product');
            $productId = $product !== null ? (int) $product->getId() : (int) $this->request->getParam('product');

            $this->attribution->markFromSearch(
                $productId,
                (string) $this->request->getParam('ns_q', '')
            );
        } catch (\Throwable $e) {
            // Silent by design — see above.
        }
    }
}
