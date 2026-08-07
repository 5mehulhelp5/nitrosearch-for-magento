<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Controller\Adminhtml\Connect;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use NitroSearch\Search\Model\ConnectService;

/**
 * Shared shape for the four buttons.
 *
 * ADMIN_RESOURCE IS THE AUTHORISATION, and it must match the ACL id declared in
 * etc/acl.xml. Magento denies an unknown resource rather than defaulting to allow,
 * so a typo here fails closed — which is the right direction, and worth knowing when
 * a button 403s for an admin who can see the page.
 *
 * EVERY ACTION REDIRECTS BACK TO THE CONFIG SECTION and reports through the message
 * manager rather than rendering anything. A merchant pressed a button on a settings
 * page; they should end up on that settings page with a sentence about what happened.
 */
abstract class AbstractAction extends Action
{
    /** Must equal the id in etc/acl.xml. */
    public const ADMIN_RESOURCE = 'NitroSearch_Search::config';

    protected ConnectService $connectService;

    public function __construct(Context $context, ConnectService $connectService)
    {
        $this->connectService = $connectService;
        parent::__construct($context);
    }

    protected function backToConfig(): ResultInterface
    {
        $result = $this->resultRedirectFactory->create();

        return $result->setPath('adminhtml/system_config/edit', ['section' => 'nitrosearch']);
    }
}
