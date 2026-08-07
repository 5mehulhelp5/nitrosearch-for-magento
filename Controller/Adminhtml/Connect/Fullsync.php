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
 * Queue the whole catalogue.
 *
 * ENQUEUE ONLY — this returns as soon as the rows are written and the cron does the
 * sending. A full walk of a large catalogue is minutes of HTTP; doing it inside an
 * admin request would hit the merchant's PHP time limit and leave them staring at a
 * spinner that turns into a 504, with no way to tell whether it worked.
 */
class Fullsync extends AbstractAction
{
    public function execute(): ResultInterface
    {
        $queued = $this->connectService->startFullSync();

        $this->messageManager->addSuccessMessage(
            __('Queued %1 products. They will be sent by cron over the next few minutes.', $queued)
        );

        return $this->backToConfig();
    }
}
