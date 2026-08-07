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
 * Adds this store's own engine host to `connect-src`, at runtime.
 *
 * WHY THIS CANNOT BE `etc/csp_whitelist.xml`. That file is static, and the Typesense
 * engine host is per-store configuration — the service assigns it when a store
 * connects. A static whitelist naming one host would be correct for one store and
 * wrong for every other, and "wrong" here means the shopper's browser silently
 * refuses the search request while the page looks fine.
 *
 * Runs on every storefront request, so it reads one already-cached setting and adds
 * one host. It returns the collected policies unchanged when there is no engine host
 * — an unverified store — rather than adding an empty entry, because an empty host in
 * a fetch policy is not neutral: it can narrow a directive that was previously
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
        $host = (string) $this->settings->get('ENGINE_HOST');

        if ($host === '') {
            return $defaultPolicies;
        }

        $defaultPolicies[] = new FetchPolicy('connect-src', false, [$host]);

        return $defaultPolicies;
    }
}
