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
 * one. The triggers are deliberately left alone — they are removed by
 * `nitrosearch:unsubscribe`, because a merchant who disconnects to re-connect
 * would otherwise lose every change made in between.
 */
class Disconnect extends AbstractAction
{
    public function execute(): ResultInterface
    {
        $this->connectService->disconnect();

        $this->messageManager->addSuccessMessage(
            __('Disconnected. Credentials deleted. Change detection is still installed — run '
                . 'bin/magento nitrosearch:unsubscribe if you are removing the module.')
        );

        return $this->backToConfig();
    }
}
