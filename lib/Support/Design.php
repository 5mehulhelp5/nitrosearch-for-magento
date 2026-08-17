<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

namespace NitroSearch\Support;

use NitroSearch\SettingsReader;

/**
 * Resolves the merchant's appearance choices into widget design tokens.
 *
 * THE PRESET NAMES NEVER LEAVE THIS FILE. "Roomy", "compact", "dark" are
 * vocabulary for the Configure screen; what the widget receives is `--ns-*` token
 * VALUES. That split is what lets one shared bundle serve every shop on every
 * platform without learning a single preset name, and it means adding a preset
 * here needs no widget release.
 *
 * WHAT IS EMITTED, STATED ACCURATELY: the four density tokens ALWAYS go, because
 * the look is a complete set and sending three of four would leave the fourth at
 * whatever the widget's default happens to be — a combination nobody chose.
 * Everything else is emitted only when it differs from the widget's own default,
 * so a shop on the defaults sends four short strings and nothing more.
 *
 * WHY THIS IS AN INSTANCE AND PRESTASHOP'S IS STATIC. The values live behind an
 * instance of {@see \NitroSearch\Settings}, which holds a `SettingsStore` bound to
 * Magento's configuration API, so there is no static accessor to reach for.
 *
 * THE TOKEN TABLES AND THE TWO COLOUR HELPERS ARE DELIBERATELY IDENTICAL to the
 * PrestaShop and OpenCart implementations — a shopper should get the same panel
 * from the same choice whichever cart the shop runs — and `WidgetConfigContractTest`
 * in the service repo reads every one of them to prove none sends a `theme` key the
 * widget does not read.
 *
 * ⚠ THIS FILE IS SHARED BY VALUE, NOT BY REFERENCE, and that is the one thing to be
 * careful about here. `lib/` is vendored between connectors, and a per-platform
 * constant once survived a copy into this module unnoticed. Everything below is
 * genuinely platform-independent: preset names in, `--ns-*` values out. Nothing
 * Magento-specific belongs in it.
 */
final class Design
{
    /** @var SettingsReader */
    private $settings;

    /**
     * Density tokens per look. Keys map to `--ns-*` custom properties.
     *
     * @var array<string, array<string, string>>
     */
    private static $looks = array(
        // The default: two-line names and a thumbnail big enough to recognise.
        'roomy' => array('thumb' => '48px', 'rowPad' => '10px', 'nameLines' => '2', 'size' => '14px'),
        // ~40% more rows before scrolling, for long catalogues of short names.
        'compact' => array('thumb' => '36px', 'rowPad' => '6px', 'nameLines' => '1', 'size' => '13px'),
        // Image-led shops: a bigger picture inside the same row, no second layout.
        'images' => array('thumb' => '72px', 'rowPad' => '12px', 'nameLines' => '2', 'size' => '14px'),
        // No thumbnails at all — B2B, spares, or shops without good photography.
        'text' => array('thumb' => '0px', 'rowPad' => '8px', 'nameLines' => '2', 'size' => '14px'),
    );

    /** @var array<string, string> */
    private static $corners = array('rounded' => '12px', 'soft' => '6px', 'square' => '0');

    /**
     * Panel/text/chrome colours for the non-custom schemes.
     *
     * @var array<string, array<string, string>>
     */
    private static $schemes = array(
        'dark' => array(
            'bg' => '#111827', 'text' => '#f9fafb', 'muted' => '#9ca3af',
            'border' => '#374151', 'chipBg' => '#1f2937', 'surface2' => '#1f2937',
        ),
    );

    public function __construct(SettingsReader $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Every value the Configure screen may set, and the only ones it may set.
     *
     * The screen POSTs whatever the merchant chose; this is what the module will
     * accept from it. Returned rather than written down twice so the form, the save
     * handler and the tests all read the same list.
     *
     * @return array<string, array<int, string>> key => allowed values ([] = free text)
     */
    public static function choices()
    {
        return array(
            'DESIGN_LOOK' => array('roomy', 'compact', 'images', 'text'),
            'DESIGN_SCHEME' => array('light', 'dark', 'auto'),
            'DESIGN_CORNERS' => array('rounded', 'soft', 'square'),
            'DESIGN_ACCENT' => array(),
            'DESIGN_WIDTH' => array('auto', 'wide', 'match'),
            'DESIGN_FILTERS' => array('auto', 'top', 'off'),
        );
    }

    /**
     * The default for each choice — the value that emits nothing.
     *
     * ONE SOURCE, because there were three: the `get()` calls below, the Configure
     * screen's pre-selected option, and a test's idea of which values are inert.
     * They agreed until they didn't — `auto` is the default for width and filters
     * but NOT for scheme, where it is a real setting that turns on `autoDark`, and
     * a flat list of "default-looking" values got that wrong on the first run.
     *
     * @return array<string, string>
     */
    public static function defaults()
    {
        return array(
            'DESIGN_LOOK' => 'roomy',
            'DESIGN_SCHEME' => 'light',
            'DESIGN_CORNERS' => 'rounded',
            'DESIGN_ACCENT' => '',
            'DESIGN_WIDTH' => 'auto',
            'DESIGN_FILTERS' => 'auto',
        );
    }

    /**
     * The `theme` half of the widget config.
     *
     * @return array<string, string|bool>
     */
    public function theme()
    {
        $theme = array();

        $look = (string) $this->settings->get('DESIGN_LOOK', self::defaults()['DESIGN_LOOK']);
        $tokens = isset(self::$looks[$look]) ? self::$looks[$look] : self::$looks['roomy'];
        foreach ($tokens as $token => $value) {
            $theme[$token] = $value;
        }

        $scheme = (string) $this->settings->get('DESIGN_SCHEME', self::defaults()['DESIGN_SCHEME']);
        if ($scheme === 'dark') {
            $theme = array_merge($theme, self::$schemes['dark']);
        } elseif ($scheme === 'auto') {
            // The widget carries the dark palette for this one case and applies it
            // behind prefers-color-scheme. Sending ten more tokens could not work:
            // the switch has to happen in CSS, on the shopper's own device, because
            // only the device knows which mode it is in at render time.
            $theme['autoDark'] = true;
        }

        $corner = (string) $this->settings->get('DESIGN_CORNERS', self::defaults()['DESIGN_CORNERS']);
        if ($corner !== 'rounded' && isset(self::$corners[$corner])) {
            $theme['radius'] = self::$corners[$corner];
        }

        $accent = self::hex((string) $this->settings->get('DESIGN_ACCENT', self::defaults()['DESIGN_ACCENT']));
        if ($accent !== '') {
            $theme['accent'] = $accent;
            // Label text on the accent is decided HERE, not by the widget: the widget
            // would have to ship a colour-contrast routine to work it out, and it is
            // one boolean we already know the answer to.
            //
            // ⚠ THE KEY IS `accentContrast`. PrestaShop shipped it as `onAccent` for
            // weeks — correctly computed, on the right condition, assembled, sent, and
            // thrown away, because the widget reads `theme.accentContrast` and nothing
            // else. The fallback is `#ffffff`, right for the dark accents most shops
            // pick and invisible for a light one. Nothing errors; a cfg key has no
            // schema on either side, so a typo here is silent by construction.
            $theme['accentContrast'] = self::isLight($accent) ? '#111827' : '#ffffff';
        }

        return $theme;
    }

    /**
     * The `layout` half: behaviour the widget decides in JS rather than CSS.
     *
     * @return array<string, string|int>
     */
    public function layout()
    {
        $layout = array();

        $width = (string) $this->settings->get('DESIGN_WIDTH', self::defaults()['DESIGN_WIDTH']);
        if (in_array($width, array('wide', 'match'), true)) {
            $layout['width'] = $width;
        }

        $filters = (string) $this->settings->get('DESIGN_FILTERS', self::defaults()['DESIGN_FILTERS']);
        if (in_array($filters, array('top', 'off'), true)) {
            $layout['facets'] = $filters;
        }

        return $layout;
    }

    /**
     * Normalise one posted value, or null when it is not acceptable.
     *
     * REJECTS RATHER THAN CLAMPS for the enumerated keys, because every one of them
     * has a meaningful default and a value outside the list can only come from a
     * hand-made request. Returning null lets the caller leave the stored value alone
     * instead of writing a guess.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return string|null
     */
    public static function normalise($key, $value)
    {
        $choices = self::choices();

        if (!isset($choices[$key])) {
            return null;
        }

        $value = is_scalar($value) ? trim((string) $value) : '';

        // Free text: the accent, which is a colour or it is nothing.
        if ($choices[$key] === array()) {
            return $value === '' ? '' : (self::hex($value) ?: null);
        }

        return in_array($value, $choices[$key], true) ? $value : null;
    }

    /**
     * Accept only a literal hex colour.
     *
     * These values are interpolated into CSS custom properties on a live
     * storefront, so anything that is not unambiguously a colour is dropped rather
     * than escaped — there is no legitimate merchant input here that needs `url(`,
     * a semicolon, or a closing brace.
     *
     * @param string $value
     *
     * @return string '' when it is not a colour
     */
    private static function hex($value)
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if ($value[0] !== '#') {
            $value = '#' . $value;
        }

        return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value) ? strtolower($value) : '';
    }

    /**
     * WCAG relative luminance, used only to choose black or white label text.
     *
     * @param string $hex
     *
     * @return bool
     */
    private static function isLight($hex)
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return true;
        }

        $channels = array();
        foreach (array(0, 2, 4) as $offset) {
            $c = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        }

        $luminance = (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);

        return $luminance > 0.179;
    }
}
