<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Model;

use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use NitroSearch\Settings;

/**
 * Adds THIS STORE'S OWN NitroSearch hosts to the storefront policy, at runtime.
 *
 * WHY THIS CANNOT BE `etc/csp_whitelist.xml`. That file is static, and every host the
 * widget talks to is per-store configuration — the service assigns all of them when a
 * store connects. A static whitelist naming one host is correct for one store and
 * wrong for every other, and "wrong" here means the shopper's browser silently
 * refuses the request while the page looks fine.
 *
 * THIS ORIGINALLY COVERED THE ENGINE HOST ALONE, and the argument above was written
 * out in full to explain why it had to. The same argument applies unchanged to the
 * widget's own script and to the analytics endpoint — both are values the service
 * hands out, both were left to a hardcoded literal in the static whitelist, and
 * neither had ever been run against a policy that enforces. Installing Hyvä is what
 * exposed it: on a strict-CSP storefront the loader is refused outright, the widget
 * never appears, and **the only trace is a console message on the shopper's machine**.
 * The same class of drift as [D-059], where the widget base URL a store was handed
 * was a developer's laptop and nothing in the deploy looked at it.
 *
 * SO EVERY HOST IS DERIVED FROM WHAT THE SERVICE ACTUALLY SENT, never from a
 * constant. `cdn.nitrosearch.io` and `api.nitrosearch.io` stay in the static file as
 * a floor for a store that has connected but not yet been handed its URLs; a merchant
 * on a staging service, a regional CDN or a future host is covered by what their own
 * store was told.
 *
 * Runs on every storefront request, so it reads already-cached settings and adds at
 * most three hosts. An empty setting is SKIPPED rather than added, because an empty
 * host in a fetch policy is not neutral: it can narrow a directive that was previously
 * unrestricted.
 */
class CspPolicy implements PolicyCollectorInterface
{
    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * @param \Magento\Csp\Api\Data\PolicyInterface[] $defaultPolicies
     *
     * @return \Magento\Csp\Api\Data\PolicyInterface[]
     */
    public function collect(array $defaultPolicies = []): array
    {
        // The engine host arrives as a bare origin; the other three arrive as full
        // URLs, so they are reduced to their host before they can be allowed.
        $connect = $this->hosts(['ENGINE_HOST', 'EVENTS_URL']);
        $script = $this->hosts(['WIDGET_LOADER_URL', 'WIDGET_BUNDLE_URL']);

        if ($connect !== []) {
            $defaultPolicies[] = new FetchPolicy('connect-src', false, $connect);
        }

        if ($script !== []) {
            $defaultPolicies[] = new FetchPolicy('script-src', false, $script);
        }

        return $defaultPolicies;
    }

    /**
     * The distinct hosts behind a set of settings, blanks dropped.
     *
     * A host is emitted WITH ITS SCHEME AND PORT when it has them, because a policy
     * entry of `localhost` does not allow `http://localhost:8000` — the port is part
     * of the origin, and a bundle served from a non-default port is exactly the case
     * a sandbox and a self-hosted service both present.
     *
     * @param string[] $keys
     *
     * @return string[]
     */
    private function hosts(array $keys): array
    {
        $out = [];

        foreach ($keys as $key) {
            $value = trim((string) $this->settings->get($key));

            if ($value === '') {
                continue;
            }

            $parts = parse_url($value);

            if ($parts === false || !isset($parts['host'])) {
                // A setting that is already a bare host, which is how ENGINE_HOST
                // arrives on some stores. Anything with a path is not a host and is
                // dropped rather than guessed at.
                if (strpos($value, '/') === false) {
                    $out[$value] = true;
                }

                continue;
            }

            $origin = $parts['host'];

            if (isset($parts['scheme'])) {
                $origin = $parts['scheme'] . '://' . $origin;
            }

            if (isset($parts['port'])) {
                $origin .= ':' . $parts['port'];
            }

            $out[$origin] = true;
        }

        return array_keys($out);
    }
}
