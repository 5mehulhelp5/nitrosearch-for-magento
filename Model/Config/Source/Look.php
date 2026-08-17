<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

declare(strict_types=1);

namespace NitroSearch\Search\Model\Config\Source;

/** Magento resolves a source model by class name, so each field needs a type. */
final class Look extends DesignChoice
{
    protected function key(): string
    {
        return 'DESIGN_LOOK';
    }
}
