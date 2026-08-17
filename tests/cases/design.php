<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

/**
 * THE APPEARANCE LAYER, where being wrong is silent on every storefront.
 *
 * A `theme` key the widget does not read is assembled, serialised, sent, and
 * thrown away — no error anywhere. The sibling PrestaShop module shipped exactly
 * that for weeks (`onAccent` where the widget reads `accentContrast`), and the
 * only reason it was ever found is that another connector emitted the same value
 * under the right name. A cfg key has no schema on either side.
 *
 * So these assertions are about the CONTRACT, not the look: the names that go on
 * the wire, the values a hostile POST can and cannot store, and the fact that a
 * shop which has chosen nothing still gets a complete set of density tokens.
 */

// Only the READ INTERFACE, never the concrete Settings — that one is final and
// holds OpenCart's `$db`, so type-hinting it would make this layer testable only
// with a real shop behind it. SettingsReader exists for exactly this reason.
require_once dirname(dirname(__DIR__)) . '/lib/SettingsReader.php';
require_once dirname(dirname(__DIR__)) . '/lib/Support/Design.php';

use NitroSearch\Support\Design;

/**
 * A Settings stand-in. The real one needs OpenCart's `$db`, and Design only ever
 * calls `get()` — so a fake keeps this runner free of a shop, which is the whole
 * premise of `tests/`.
 */
class NsFakeSettings implements \NitroSearch\SettingsReader
{
    /** @var array<string, mixed> */
    private $values;

    /** @param array<string, mixed> $values */
    public function __construct(array $values = array())
    {
        $this->values = $values;
    }

    public function get($key, $default = null)
    {
        if (isset($this->values[$key]) && $this->values[$key] !== '') {
            return $this->values[$key];
        }

        return $default !== null ? $default : '';
    }
}

return array(

    'a shop that has chosen nothing still gets a complete look' => function () {
        $theme = (new Design(new NsFakeSettings()))->theme();

        // ALL FOUR, ALWAYS. A look is a complete set: sending three of four leaves
        // the fourth at whatever the widget's default happens to be, which is a
        // combination no merchant chose and nobody could reproduce from the screen.
        ns_is('thumb', '48px', $theme['thumb']);
        ns_is('rowPad', '10px', $theme['rowPad']);
        ns_is('nameLines', '2', $theme['nameLines']);
        ns_is('size', '14px', $theme['size']);

        // …and nothing else, because nothing else was chosen.
        ns_is('no accent', false, isset($theme['accent']));
        ns_is('no radius', false, isset($theme['radius']));
        ns_is('no autoDark', false, isset($theme['autoDark']));
    },

    'the layout half is empty until something is chosen' => function () {
        // An empty key on the wire is noise every page load has to parse.
        ns_is('defaults emit nothing', array(), (new Design(new NsFakeSettings()))->layout());

        $chosen = new NsFakeSettings(array('DESIGN_WIDTH' => 'wide', 'DESIGN_FILTERS' => 'off'));
        $layout = (new Design($chosen))->layout();

        ns_is('width', 'wide', $layout['width']);
        ns_is('facets', 'off', $layout['facets']);
    },

    'the accent carries its own contrast, under the name the widget reads' => function () {
        $dark = (new Design(new NsFakeSettings(array('DESIGN_ACCENT' => '#111827'))))->theme();

        // ⚠ `accentContrast`, NOT `onAccent`. This assertion is the whole reason
        // this case exists — see the file header.
        ns_is('accent stored', '#111827', $dark['accent']);
        ns_is('white on a dark accent', '#ffffff', $dark['accentContrast']);
        ns_is('no onAccent key', false, isset($dark['onAccent']));

        $light = (new Design(new NsFakeSettings(array('DESIGN_ACCENT' => '#fde047'))))->theme();

        // The case that fails invisibly: a pale accent with white label text is
        // unreadable, and nothing anywhere reports it.
        ns_is('black on a light accent', '#111827', $light['accentContrast']);
    },

    'a colour that is not a colour is dropped, not escaped' => function () {
        // These are interpolated into CSS custom properties on a live storefront.
        foreach (array('red', 'url(x)', '#12', '#1234', '12345g', '#fff;color:red') as $bad) {
            $theme = (new Design(new NsFakeSettings(array('DESIGN_ACCENT' => $bad))))->theme();
            ns_is('rejects ' . $bad, false, isset($theme['accent']));
        }

        // Both lengths, with and without the hash, normalised to lowercase.
        ns_is('short form', '#abc', (new Design(new NsFakeSettings(array('DESIGN_ACCENT' => 'ABC'))))->theme()['accent']);
        ns_is('long form', '#aabbcc', (new Design(new NsFakeSettings(array('DESIGN_ACCENT' => '#AABBCC'))))->theme()['accent']);
    },

    'auto dark is a flag, not a palette' => function () {
        $auto = (new Design(new NsFakeSettings(array('DESIGN_SCHEME' => 'auto'))))->theme();

        // The switch has to happen in CSS on the shopper's own device, because only
        // the device knows which mode it is in at render time. Sending the ten dark
        // tokens here could not work.
        ns_true('flag set', $auto['autoDark']);
        ns_is('no bg token', false, isset($auto['bg']));

        $dark = (new Design(new NsFakeSettings(array('DESIGN_SCHEME' => 'dark'))))->theme();
        ns_is('explicit dark sends the palette', '#111827', $dark['bg']);
        ns_is('and no flag', false, isset($dark['autoDark']));
    },

    'an unknown preset falls back rather than emitting nothing' => function () {
        // Forward tolerance, the same rule the currency table follows: a stored
        // value this version has never heard of must still render a usable panel.
        $theme = (new Design(new NsFakeSettings(array('DESIGN_LOOK' => 'holographic'))))->theme();

        ns_is('falls back to roomy', '48px', $theme['thumb']);
    },

    'normalise rejects rather than guesses' => function () {
        ns_is('good preset', 'compact', Design::normalise('DESIGN_LOOK', 'compact'));
        ns_is('bad preset', null, Design::normalise('DESIGN_LOOK', 'holographic'));
        ns_is('unknown key', null, Design::normalise('DESIGN_NONSENSE', 'x'));

        // A rejected value must be distinguishable from a cleared one: '' means the
        // merchant emptied the accent field, null means they sent something we will
        // not store — and the caller keeps the old value in the second case.
        ns_is('cleared accent', '', Design::normalise('DESIGN_ACCENT', ''));
        ns_is('bad accent', null, Design::normalise('DESIGN_ACCENT', 'javascript:1'));
        ns_is('good accent', '#00ff88', Design::normalise('DESIGN_ACCENT', '00FF88'));
    },

    'every choice this module offers is one the resolver understands' => function () {
        // The form, the save handler and the read-back all derive from choices().
        // If an entry there has no effect in theme()/layout(), the screen offers a
        // control that does nothing — which looks exactly like a broken widget.
        foreach (Design::choices() as $key => $allowed) {
            foreach ($allowed as $value) {
                ns_is(
                    $key . '=' . $value . ' is accepted',
                    $value,
                    Design::normalise($key, $value)
                );
            }
        }

        // …and the enumerated ones actually change something. The accent is exempt:
        // it is free text and is covered above.
        $base = (new Design(new NsFakeSettings()))->theme();
        $baseLayout = (new Design(new NsFakeSettings()))->layout();

        foreach (Design::choices() as $key => $allowed) {
            foreach ($allowed as $value) {
                $d = new Design(new NsFakeSettings(array($key => $value)));
                $changed = $d->theme() !== $base || $d->layout() !== $baseLayout;

                // PER KEY, from Design's own table. A flat list of "default-looking"
                // values got this wrong immediately: `auto` is the default for width
                // and filters, and a real setting for scheme.
                $isDefault = $value === Design::defaults()[$key];

                ns_is($key . '=' . $value . ' has an effect', !$isDefault, $changed);
            }
        }
    },
);
