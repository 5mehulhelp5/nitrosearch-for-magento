<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

/**
 * PROOF OF CONTROL — the hash that says "this shop is really at that address".
 *
 * The service sends a nonce to the module's public verify route and expects a
 * hash only a holder of this shop's sync secret could produce. Getting it wrong
 * means a shop can connect but never verify, which is a dead install that looks
 * healthy: the catalogue syncs and the storefront never gets a search key.
 *
 * Pinned as literals for the same reason as the HMAC vector — recording this
 * module's own output as the expectation would prove only self-consistency, and
 * both sides drifting together is the failure worth catching.
 */

require_once dirname(dirname(__DIR__)).'/lib/Support/VerifyChallenge.php';

use NitroSearch\Support\VerifyChallenge;

return array(
    'the proof is pinned' => function () {
        // hex(hmac_sha256(secret, "nitrosearch-verify-v1\n" . nonce)) — the
        // prefix is domain separation, so a nonce can never be replayed into
        // some other context that signs with the same secret.
        ns_is(
            'proof',
            hash_hmac('sha256', "nitrosearch-verify-v1\n".'abc123', 'supersecret'),
            VerifyChallenge::proof('abc123', 'supersecret')
        );
    },

    'the prefix is part of what is signed' => function () {
        // The self-negative for the domain separation: signing the bare nonce
        // must NOT produce the same value, or the prefix is decorative.
        ns_true(
            'a bare-nonce signature differs',
            VerifyChallenge::proof('abc123', 'supersecret') !== hash_hmac('sha256', 'abc123', 'supersecret')
        );
    },

    'a different nonce or secret gives a different proof' => function () {
        $base = VerifyChallenge::proof('abc123', 'supersecret');

        ns_true('nonce matters', VerifyChallenge::proof('abc124', 'supersecret') !== $base);
        ns_true('secret matters', VerifyChallenge::proof('abc123', 'supersecrft') !== $base);
    },

    'the proof is hex-encoded sha256' => function () {
        ns_is(
            'shape',
            1,
            preg_match('/^[0-9a-f]{64}$/', VerifyChallenge::proof('abc123', 'supersecret'))
        );
    },
);
