<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

namespace NitroSearch\Support;

/**
 * The proof this shop returns to show it controls its own hostname.
 *
 * NitroSearch issues a random nonce and fetches this module's public verify
 * controller over a server-to-server request. We answer with an HMAC over the
 * sync secret. A caller that does not hold that secret cannot produce the proof,
 * so a site that merely REFLECTS the nonce never passes — which is the whole
 * point, and the reason the endpoint can safely be public.
 *
 *   proof = hex(hmac_sha256(sync_secret, "nitrosearch-verify-v1\n" + nonce))
 *
 * THE PREFIX IS DOMAIN SEPARATION, NOT DECORATION. The ingest canonical string
 * starts with "v1\n"; this one starts with "nitrosearch-verify-v1\n". Because the
 * two input spaces cannot collide, the public verify endpoint can never be used
 * as a signing oracle to forge an ingest request — someone who can ask us to sign
 * arbitrary nonces still cannot obtain a signature over an ingest canonical.
 * Removing or shortening the prefix would quietly turn a public endpoint into a
 * way to sign anything.
 */
final class VerifyChallenge
{
    /** MUST differ from Hmac::VERSION ("v1"), or the separation is lost. */
    const PROOF_PREFIX = 'nitrosearch-verify-v1';

    /**
     * @param string $nonce  the value NitroSearch sent
     * @param string $secret this shop's sync secret
     *
     * @return string hex-encoded HMAC-SHA256
     */
    public static function proof($nonce, $secret)
    {
        return hash_hmac('sha256', self::PROOF_PREFIX . "\n" . $nonce, $secret);
    }

    /**
     * Whether a nonce is one we are willing to sign at all.
     *
     * Bounded and character-checked BEFORE it reaches the HMAC. The service sends
     * 64 hex characters; anything else is not ours to sign, and refusing early
     * keeps arbitrary caller-controlled input out of the proof entirely.
     *
     * @param mixed $nonce
     *
     * @return bool
     */
    public static function acceptableNonce($nonce)
    {
        return is_string($nonce) && preg_match('/^[a-f0-9]{16,128}$/i', $nonce) === 1;
    }
}
