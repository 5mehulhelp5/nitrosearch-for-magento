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

use Magento\Framework\Data\OptionSourceInterface;

/**
 * The options behind each appearance `<select>` in Stores → Configuration.
 *
 * ONE BASE, DERIVED FROM {@see \NitroSearch\Support\Design::choices()}. Magento
 * instantiates a source model by class name with no arguments, so each field needs
 * its own type — but the LIST does not have to be written out again per field, and
 * that distinction is the whole design here. A preset added to `Design` appears in
 * the admin without this file, or any subclass, being touched.
 *
 * WHY THAT MATTERS MORE THAN IT LOOKS. The same list exists in the resolver, in the
 * save-time validation and on this screen. The sibling OpenCart module shipped a
 * release where a hand-written key list disagreed with the template it fed and
 * produced four unlabelled buttons in both archives — nothing errored, because a
 * missing option is not an error, it is just an option a merchant cannot pick.
 *
 * LABELS LIVE HERE, VALUES LIVE IN `Design`. The labels are merchant-facing prose
 * and belong with the screen; a value with no label falls back to its own name
 * rather than vanishing from the list, because an option silently missing is
 * indistinguishable from a preset that was never built.
 */
abstract class DesignChoice implements OptionSourceInterface
{
    /** Merchant-facing labels, keyed by `Design` value. */
    private const LABELS = [
        'roomy' => 'Roomy — large thumbnail, two-line names',
        'compact' => 'Compact — more results before scrolling',
        'images' => 'Image-led — largest thumbnail',
        'text' => 'Text only — no thumbnails',
        'light' => 'Light',
        'dark' => 'Dark',
        'rounded' => 'Rounded',
        'soft' => 'Slightly rounded',
        'square' => 'Square',
        'wide' => 'Wide',
        'match' => 'Match the search box',
        'top' => 'Always along the top',
        'off' => 'Hidden',
    ];

    /** Labels that differ by field — `auto` means three different things. */
    private const PER_FIELD = [
        'DESIGN_SCHEME' => ['auto' => "Match the shopper's device"],
        'DESIGN_WIDTH' => ['auto' => 'Automatic'],
        'DESIGN_FILTERS' => ['auto' => 'Automatic'],
    ];

    /** The `Design::choices()` key this field renders. */
    abstract protected function key(): string;

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $key = $this->key();
        $choices = \NitroSearch\Support\Design::choices();
        $values = $choices[$key] ?? [];

        $out = [];

        foreach ($values as $value) {
            $out[] = [
                'value' => $value,
                'label' => self::PER_FIELD[$key][$value] ?? self::LABELS[$value] ?? $value,
            ];
        }

        return $out;
    }
}
