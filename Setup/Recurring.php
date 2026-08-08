<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Setup;

use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use NitroSearch\Search\Model\Subscription;
use NitroSearch\Settings;

/**
 * Runs after every `setup:upgrade`, and makes the manual subscribe step unnecessary.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY RECURRING RATHER THAN A SCHEMA PATCH, which is what was originally planned.
 *
 * A schema patch records itself in `patch_list` and never runs again. That is right
 * for a migration and wrong for an invariant. The invariant here is "a connected
 * store has triggers", and it can be broken long after install by things that have
 * nothing to do with our code: a database restored from a dump taken before the
 * module was connected, a migration between hosts that did not carry triggers,
 * someone's cleanup script, a `nitrosearch:unsubscribe` run to debug something and
 * never undone.
 *
 * Every one of those leaves a module that looks completely healthy — installed,
 * enabled, connected, no errors anywhere — and syncs nothing. A patch cannot fix
 * that; a recurring check re-asserts the invariant on every deployment and costs one
 * row to evaluate.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * ONLY FOR A CONNECTED STORE. Subscribing creates 42 triggers across 14 catalogue
 * tables; a merchant who has installed the module to look at it has not asked for
 * that, and the work would have no consumer — the drain cannot send anything without
 * credentials. So an unconnected store gets nothing, and connecting is what turns the
 * triggers on.
 *
 * NEVER FAILS THE DEPLOYMENT. `Subscription::ensure()` returns a reason rather than
 * throwing, and this discards it. A merchant running `setup:upgrade` as part of a
 * release must not have that release fail because their database user lacks the
 * TRIGGER privilege. The state is reported by `nitrosearch:status`, which exists
 * precisely because this module's worst failure is a silent one.
 */
class Recurring implements InstallSchemaInterface
{
    private Subscription $subscription;
    private Settings $settings;

    public function __construct(Subscription $subscription, Settings $settings)
    {
        $this->subscription = $subscription;
        $this->settings = $settings;
    }

    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        try {
            if (!$this->settings->isConnected()) {
                return;
            }

            // The reason is deliberately discarded here — see the class note. It is
            // surfaced by `nitrosearch:status`, where a human is asking.
            $this->subscription->ensure();
        } catch (\Throwable $e) {
            // Belt and braces. Nothing in this class may fail a deployment.
        }
    }
}
