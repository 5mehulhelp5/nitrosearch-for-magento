<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Model;

use Magento\Framework\Mview\View\StateInterface;
use Magento\Framework\Mview\ViewInterfaceFactory;

/**
 * Creating and removing the database triggers that feed our changelog.
 *
 * ONE PLACE, BECAUSE FOUR THINGS NEED IT: the two console commands, the setup hook
 * that runs on every `setup:upgrade`, and connect/disconnect. Four copies of
 * "load the view, check the mode, call subscribe()" is four chances for one of them
 * to check the mode differently.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * TRIGGERS ARE CREATED ON CONNECT, NOT ON INSTALL — a deliberate deviation from the
 * original plan, which put a schema patch on install.
 *
 * Subscribing creates **42 triggers across 14 catalogue tables** on the merchant's
 * database. Doing that the moment a module is installed means a merchant evaluating
 * NitroSearch gets write-path overhead on their entire catalogue before they have
 * connected anything — and if they uninstall without running `unsubscribe`, triggers
 * outlive the table they write into.
 *
 * It also does work with no consumer. On an unconnected store the triggers fire, our
 * mview action runs from Magento's own minute cron, and the outbox fills with rows
 * the drain will never send because the store has no credentials. Bounded by
 * catalogue size rather than unbounded — the outbox upserts on object identity — but
 * still a table of pending work for a store that has not asked for any.
 *
 * So the triggers arrive when the merchant connects, and leave when they disconnect.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * AND {@see ensure()} IS CALLED AGAIN ON EVERY `setup:upgrade`, which is the part a
 * one-shot install patch could not do. A schema patch records itself as applied and
 * never runs again — so a store whose triggers were lost to a database restore, a
 * migration between hosts, or someone's cleanup script would never get them back, and
 * the module would look perfectly healthy while syncing nothing. Re-checking on every
 * deploy is cheap (one row) and self-heals exactly that case.
 */
class Subscription
{
    private ViewInterfaceFactory $viewFactory;

    public function __construct(ViewInterfaceFactory $viewFactory)
    {
        $this->viewFactory = $viewFactory;
    }

    public function isSubscribed(): bool
    {
        $view = $this->view();

        return $view !== null && $view->getState()->getMode() === StateInterface::MODE_ENABLED;
    }

    /**
     * Create the triggers if they are missing.
     *
     * @return string '' on success, otherwise why not — never an exception
     *
     * NEVER THROWS, AND THAT IS THE WHOLE POINT ON THE SETUP PATH. This runs inside
     * `setup:upgrade`, which merchants run as part of a deployment. If the database
     * user lacks the TRIGGER privilege — managed MySQL sometimes withholds it — an
     * exception here fails their entire deployment for one optional subsystem. That
     * is far worse than the alternative, which is a module that installs and reports
     * "subscribed: NO" in `nitrosearch:status` until someone fixes the grant.
     *
     * The reason is returned rather than swallowed, so every caller can decide how
     * loudly to say it: the console command prints it with the two known hosting
     * causes, and the setup hook lets the deployment continue.
     */
    public function ensure(): string
    {
        $view = $this->view();

        if ($view === null) {
            return 'the nitrosearch_product view is not registered; run setup:upgrade first';
        }

        if ($this->isSubscribed()) {
            return '';
        }

        try {
            $view->subscribe();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return '';
    }

    /**
     * Drop the triggers.
     *
     * THE SHARPEST BUG IN THIS MODULE'S DESIGN IS UNINSTALL, and this is the fix.
     * `composer remove` plus `setup:upgrade` drops our tables through declarative
     * schema — but **triggers are not schema**. Without this a merchant is left with
     * triggers writing into a changelog table that no longer exists, and every
     * catalogue write on their store starts failing.
     */
    public function remove(): string
    {
        $view = $this->view();

        if ($view === null || !$this->isSubscribed()) {
            return '';
        }

        try {
            $view->unsubscribe();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return '';
    }

    private function view()
    {
        try {
            $view = $this->viewFactory->create()->load('nitrosearch_product');
        } catch (\Throwable $e) {
            return null;
        }

        return $view->getId() === null ? null : $view;
    }
}
