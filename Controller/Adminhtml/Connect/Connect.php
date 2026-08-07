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
 * Connect the store.
 *
 * THE THREE OUTCOMES ARE THREE DIFFERENT MESSAGES, and conflating them is the
 * mistake worth avoiding. Connected-and-verified is success. Connected-but-unverified
 * is ALSO success — the service could not reach this storefront from outside, which
 * is the normal state on localhost, staging or a password-protected site, and telling
 * the merchant it failed sends them to disconnect and retry, which changes nothing
 * and costs them their credentials. Only a failed connect is a failure.
 */
class Connect extends AbstractAction
{
    public function execute(): ResultInterface
    {
        $result = $this->connectService->connect();

        if (!$result['ok']) {
            $this->messageManager->addErrorMessage(
                __('Could not connect: %1', $result['error'])
            );

            return $this->backToConfig();
        }

        if ($result['verified']) {
            $this->messageManager->addSuccessMessage(
                __('Connected and verified. Your catalogue will start syncing on the next cron run.')
            );
        } else {
            $this->messageManager->addWarningMessage(
                __(
                    'Connected, but not yet verified%1. This is normal on a local, staging or '
                    . 'password-protected site — we have to reach your storefront from the outside. '
                    . 'Press "Check verification" once it is publicly reachable.',
                    $result['reason'] !== '' ? __(' (%1)', $result['reason']) : ''
                )
            );
        }

        return $this->backToConfig();
    }
}
