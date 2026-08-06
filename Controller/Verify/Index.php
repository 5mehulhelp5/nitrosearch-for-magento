<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Controller\Verify;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use NitroSearch\Settings;
use NitroSearch\Support\VerifyChallenge;

/**
 * The loopback proof-of-control challenge — `GET /nitrosearch/verify/index`.
 *
 * NitroSearch issues a nonce and fetches this route server-to-server. We answer
 * with an HMAC over this shop's sync secret, so a host that merely REFLECTS the
 * nonce never passes and the route is safe to expose publicly. The
 * `nitrosearch-verify-v1` domain separator in {@see VerifyChallenge} is what stops
 * a public signing endpoint being usable as an oracle for ingest signatures.
 *
 * IMPLEMENTS THE MARKER INTERFACE rather than extending `Action\Action`. The base
 * class is soft-deprecated and drags in a full context; `HttpGetActionInterface`
 * is the current idiom and also declares — enforceably — that this route answers
 * GET only. If it ever becomes a POST it must additionally implement
 * `CsrfAwareActionInterface` and return true from `validateForCsrf()`, or Magento
 * rejects it before it reaches this class.
 *
 * THE FOUR RESPONSE RULES ARE COPIED EXACTLY FROM THE OPENCART CONNECTOR, because
 * the backend's verifier is the counterparty and it is the same verifier for every
 * platform. It requires status 200, a JSON content type, and a byte-equal proof —
 * so a single stray byte of markup reads as a failed verification, and any
 * response that is "helpful" instead of exact fails the shop.
 *
 *   1. `nonce` is read as an ordinary query parameter, never by position.
 *   2. Shape is checked BEFORE the HMAC: a malformed nonce is 400 `invalid_nonce`.
 *   3. No stored secret is 409 `not_connected`, NOT 500 — nothing is broken; the
 *      shop simply has not connected yet, and a 500 would page someone.
 *   4. Otherwise 200 with the proof.
 */
class Index implements HttpGetActionInterface
{
    private RequestInterface $request;
    private JsonFactory $resultJsonFactory;
    private Settings $settings;

    public function __construct(
        RequestInterface $request,
        JsonFactory $resultJsonFactory,
        Settings $settings
    ) {
        $this->request = $request;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->settings = $settings;
    }

    public function execute(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();

        // `no-store` IS LOAD-BEARING ON MAGENTO IN A WAY IT IS NOT ELSEWHERE.
        // Every Magento store has a full page cache, and the stock Varnish VCL
        // treats a response without it as cacheable. A cached proof is a proof
        // for someone else's nonce, so verification would fail intermittently and
        // un-debuggably — the worst possible failure for a one-shot onboarding
        // step. The other three connectors set this header out of hygiene; here
        // it is the difference between working and flaking.
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $result->setHeader('Pragma', 'no-cache', true);
        $result->setHeader('X-Robots-Tag', 'noindex, nofollow', true);

        $nonce = $this->request->getParam('nonce');

        if (!VerifyChallenge::acceptableNonce($nonce)) {
            return $result->setHttpResponseCode(400)->setData(['error' => 'invalid_nonce']);
        }

        $secret = (string) $this->settings->get('SYNC_SECRET');

        if ($secret === '') {
            return $result->setHttpResponseCode(409)->setData(['error' => 'not_connected']);
        }

        return $result->setHttpResponseCode(200)->setData([
            'proof' => VerifyChallenge::proof((string) $nonce, $secret),
        ]);
    }
}
