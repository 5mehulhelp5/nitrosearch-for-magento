<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Controller\Adminhtml\Connect;

use Magento\Framework\Controller\ResultInterface;

/**
 * Forget everything.
 *
 * Purges the whole config group rather than clearing named fields, so a value
 * introduced by a newer version cannot survive a disconnect performed by an older
 * one.
 *
 * ⚠ THE TRIGGERS GO TOO, and this docblock said the exact opposite until 2026-08-11.
 * It claimed they were "deliberately left alone" and removed only by
 * `nitrosearch:unsubscribe`, while `ConnectService::disconnect()` has always called
 * `Subscription::remove()` — which calls Magento's own `unsubscribe()` — as its FIRST
 * action, with its own comment explaining why that order matters. Both the docblock
 * and the merchant-facing message described a module that does not exist, and the
 * message sent merchants to run a command that would have found nothing to do.
 *
 * The message now reports what actually happened, and says something different in the
 * one case where it genuinely did not work.
 */
class Disconnect extends AbstractAction
{
    public function execute(): ResultInterface
    {
        $error = $this->connectService->disconnect();

        if ($error !== '') {
            // The credentials are gone either way — `disconnect()` purges them after
            // the trigger removal is attempted — so this is not a failure the merchant
            // can retry from the button. Name the command that can finish the job.
            $this->messageManager->addErrorMessage(
                __('Disconnected and credentials deleted, but change detection could not be '
                    . 'removed: %1. Run bin/magento nitrosearch:unsubscribe to finish.', $error)
            );

            return $this->backToConfig();
        }

        $this->messageManager->addSuccessMessage(
            __('Disconnected. Credentials deleted and change detection removed.')
        );

        return $this->backToConfig();
    }
}
