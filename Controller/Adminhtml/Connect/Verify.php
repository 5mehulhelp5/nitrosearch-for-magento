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
 * Re-ask for verification — the button a merchant presses after fixing their hosting.
 *
 * It also fetches the search key on success, because verification usually happens
 * through the service's own loopback rather than through a call this module made: a
 * store can become verified without the module learning it, and without the key the
 * storefront widget has nothing to search with.
 */
class Verify extends AbstractAction
{
    public function execute(): ResultInterface
    {
        $result = $this->connectService->refresh();

        if ($result['reason'] === 'not_connected') {
            $this->messageManager->addErrorMessage(__('This store is not connected yet.'));

            return $this->backToConfig();
        }

        if ($result['verified']) {
            $this->messageManager->addSuccessMessage(__('Verified. Storefront search is live.'));
        } else {
            $this->messageManager->addWarningMessage(
                __(
                    'Still not verified%1. We fetch a route on your storefront to prove you '
                    . 'control it, so it has to be reachable from the public internet.',
                    $result['reason'] !== '' ? __(' (%1)', $result['reason']) : ''
                )
            );
        }

        return $this->backToConfig();
    }
}
