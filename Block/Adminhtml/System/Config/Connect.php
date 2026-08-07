<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 */

declare(strict_types=1);

namespace NitroSearch\Search\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use NitroSearch\Settings;

/**
 * The status panel and the buttons, rendered inside the config section.
 *
 * A CONFIG FIELD IS THE ONLY PLACE AN ACTION CAN LIVE IN A CONFIG SECTION, so this
 * is a field with no value. Magento's config form posts the entire section, so a
 * plain submit button could not report what happened to one action; these post to
 * the admin controller directly and come back with a message.
 *
 * NO SECRET IS EVER RENDERED. `Settings::publicValues()` strips the credential keys,
 * and this block reads only from that. A sync secret in admin markup is a sync secret
 * in a browser cache, a screenshot and a support ticket.
 */
class Connect extends Field
{
    protected $_template = 'NitroSearch_Search::system/config/connect.phtml';

    private Settings $settings;

    public function __construct(Context $context, Settings $settings, array $data = [])
    {
        $this->settings = $settings;
        parent::__construct($context, $data);
    }

    /** The section's own scope label and value are meaningless for an action row. */
    public function render(AbstractElement $element): string
    {
        $element->setInherit(1);

        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    public function isConnected(): bool
    {
        return $this->settings->isConnected();
    }

    public function isVerified(): bool
    {
        return (bool) $this->settings->get('VERIFIED');
    }

    public function getStoreRef(): string
    {
        return (string) $this->settings->get('STORE_ID');
    }

    public function getLastError(): string
    {
        return (string) $this->settings->get('LAST_ERROR');
    }

    public function getLastSync(): string
    {
        return (string) $this->settings->get('LAST_SYNC');
    }

    public function getActionUrl(string $action): string
    {
        return $this->getUrl('nitrosearch/connect/' . $action);
    }
}
