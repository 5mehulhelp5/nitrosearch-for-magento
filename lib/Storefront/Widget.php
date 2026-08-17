<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the GNU General Public License v3.0 (GPL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/GPL-3.0
 */

namespace NitroSearch\Storefront;

use NitroSearch\Settings;
use NitroSearch\Support\Design;

/**
 * `window.NitroSearchConfig` plus the loader script, for the shop's `<head>`.
 *
 * THE LOADER IS ~1.3 KB AND FETCHES NOTHING UNTIL A SHOPPER SHOWS SEARCH INTENT.
 * It enhances the theme's own search input in place; the widget bundle is only
 * requested on the first focus or on a search-results page. A visitor who never
 * searches downloads the shim and nothing else, which is why it is acceptable to
 * put this on every page rather than on the search page alone — the widget has to
 * be attached to the header search box, and that box is on every page.
 *
 * FRAMEWORK-FREE, LIKE EVERYTHING IN src/. The shop's url, currency and language
 * are passed in: the two majors expose all three differently, and this class is
 * copied verbatim into both archives. {@see \NitroSearch\Sync\Runner::storefront()}
 * resolves them once, from the shop's own settings rather than from the ambient
 * request, so a page rendered for a logged-in customer emits the same config as one
 * rendered for a crawler.
 *
 * IT EMITS NOTHING UNTIL EVERY PIECE IS PRESENT. A connected-but-unverified shop
 * has no scoped key, and a loader on such a page is a script that can only fail —
 * so the whole block is withheld rather than emitted half-formed. Same for a shop
 * whose service has not yet told it where the bundles live.
 */
final class Widget
{
    /** @var Settings */
    private $settings;

    /** @var string this shop's storefront base url */
    private $siteUrl;

    /** @var string ISO-4217, the currency the catalogue is priced in */
    private $currency;

    /** @var string the shop's language code, e.g. `en-gb`; '' when unknown */
    private $locale;

    /**
     * @param string $siteUrl
     * @param string $currency
     * @param string $locale
     */
    public function __construct(Settings $settings, $siteUrl, $currency, $locale)
    {
        $this->settings = $settings;
        $this->siteUrl = rtrim((string) $siteUrl, '/');
        $this->currency = (string) $currency;
        $this->locale = (string) $locale;
    }

    /**
     * Everything the widget needs, or null when this shop is not ready to search.
     *
     * @return array<string, mixed>|null
     */
    public function config()
    {
        if (!$this->settings->isConnected()) {
            return null;
        }

        $key = (string) $this->settings->get('SCOPED_SEARCH_KEY');
        $host = (string) $this->settings->get('ENGINE_HOST');
        $collection = (string) $this->settings->get('COLLECTION');
        $loaderUrl = $this->loaderUrl();
        $bundleUrl = (string) $this->settings->get('WIDGET_BUNDLE_URL');

        // EVERY ONE OF THESE IS LOAD-BEARING and none has a usable default. The
        // scoped key and collection are what the loader itself checks before it does
        // anything; the engine host is where the browser sends its queries; the two
        // urls are the assets. Missing any of them means the shop is connected but
        // not yet verified, which is a normal state and not an error to shout about.
        if ($key === '' || $host === '' || $collection === '' || $loaderUrl === '' || $bundleUrl === '') {
            return null;
        }

        $config = array(
            'engine' => array('host' => $host),
            'collection' => $collection,
            'scopedKey' => $key,
            'bundleUrl' => $bundleUrl,
            'siteUrl' => $this->siteUrl,
            'currency' => $this->currency !== '' ? $this->currency : 'USD',
            // Results-page takeover on `product/search`. On by default, matching the
            // other platforms; there is no merchant toggle in this version, and when
            // one arrives it belongs here rather than in either adapter.
            'results' => (bool) $this->settings->get('RESULTS_TAKEOVER'),
            // OPENCART INDEXES PRODUCTS ONLY. Sending this false is not a formality:
            // the widget issues a SECOND engine query per keystroke when content is
            // on, and a shop with no indexed pages would pay for every one of them
            // and get nothing back.
            'content' => false,
            // "Powered by NitroSearch" is OFF. A credit on a merchant's storefront
            // must be their choice, and this module has no screen on which to make
            // it yet — so the honest default is not to place one. The widget shows
            // the badge unless told otherwise, so this key cannot be omitted.
            'badge' => (bool) $this->settings->get('SHOW_BADGE'),
            // The merchant's opt-out for the anonymous usage beacon, and THIS KEY
            // CANNOT BE OMITTED EITHER. The widget declines to emit only on an
            // explicit `cfg.analytics === false`; an absent key is `undefined`,
            // which is not `false`, so leaving it out means always-on with no way
            // to decline. That is what this module shipped until now — the setting
            // did not exist, the key was never sent, and the service issues the
            // events token to every verified store, so there was no layer at which
            // a merchant could say no. Sent even when true, so the value on the
            // page is always the merchant's actual choice rather than a default
            // inferred from silence.
            'analytics' => (bool) $this->settings->get('SHARE_SEARCH_DATA'),
        );

        // APPEARANCE. `theme` always carries the four density tokens because a look
        // is a complete set; `layout` is omitted entirely when the merchant has moved
        // nothing off its default, since an empty key is noise every page load parses.
        $design = new Design($this->settings);

        $theme = $design->theme();
        if ($theme !== array()) {
            $config['theme'] = $theme;
        }

        $layout = $design->layout();
        if ($layout !== array()) {
            $config['layout'] = $layout;
        }

        // THE LOCALE IS NOT ONLY A TRANSLATION SWITCH — the widget formats prices
        // with it, so withholding it leaves every shop on generic English number
        // conventions. Sent even though this module ships no translated catalogues,
        // for exactly that reason. Omitted only when the shop has no configured
        // language at all, where the widget's own 'en' fallback is as good a guess
        // as any we could make.
        if ($this->locale !== '') {
            $config['locale'] = $this->locale;
        }

        // NO `labels` KEY, DELIBERATELY. The widget's built-in strings are English
        // and this module has no gettext catalogues, so there is nothing to send —
        // and sending untranslated English under a non-English locale would make
        // the widget select plurals by that locale's rules for English text.

        // The anonymous usage beacon. Omitted entirely until the service has issued
        // a token, which it does only for a verified store — the widget no-ops
        // without it, so an unverified shop costs nothing by not having one.
        $eventsUrl = (string) $this->settings->get('EVENTS_URL');
        $eventsToken = (string) $this->settings->get('EVENTS_TOKEN');
        if ($eventsUrl !== '' && $eventsToken !== '') {
            $config['events'] = array('url' => $eventsUrl, 'token' => $eventsToken);
        }

        // NO `cart` KEY, DELIBERATELY. The widget derives OpenCart's add-to-cart
        // route from the page itself — `<base href>` for the shop url, and whether
        // any internal link carries `language=` to tell the two majors' route forms
        // apart. Both were verified against real stores on both majors. Pinning it
        // here from PHP would have to guess the major, which is the one thing the
        // runtime signal makes unnecessary. Setting it is for a shop that has
        // genuinely moved its cart endpoint.

        return $config;
    }

    /**
     * The `<script>` pair, or '' when this shop is not ready to search.
     *
     * @return string
     */
    public function markup()
    {
        $config = $this->config();
        if ($config === null) {
            return '';
        }

        // `JSON_HEX_TAG` IS THE SECURITY-RELEVANT FLAG, and it is a flag rather than
        // a str_replace on purpose. It escapes every `<` and `>` in the encoded
        // output to the JSON escapes `\u003C` and `\u003E`, so a `</script>`
        // reaching this config cannot close the block early and start executing
        // markup.
        // Nothing we put in here should contain one — but the shop's own configured
        // values reach this function, and "should not" is not a security property.
        //
        // A HAND-ROLLED `str_replace` OF THE SAME IDEA IS ONE TYPO FROM A SILENT
        // NO-OP, and a reviewer reading the line cannot see the difference: the
        // needle and the replacement are both one short string, and a version that
        // replaces `<` with `<` compiles, runs, escapes nothing, and reads exactly
        // like one that works. Let the engine do it.
        $json = json_encode($config, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return '';
        }

        return '<script>window.NitroSearchConfig=' . $json . ';</script>' . "\n"
            . '<script src="' . htmlspecialchars($this->loaderUrl(), ENT_QUOTES, 'UTF-8') . '" defer></script>' . "\n";
    }

    /**
     * Put the markup into a rendered page, immediately before `</head>`.
     *
     * TAKES AND RETURNS THE WHOLE HEADER OUTPUT because that is what OpenCart's view
     * event hands over, in both majors. It is the only injection point the two share
     * without a template edit: `document->addScript()` exists in both and takes a
     * URL only, so the config blob — which must be defined before the loader runs —
     * has nowhere to go through it.
     *
     * FALLS BACK TO APPENDING when a theme's header has no `</head>`. That places
     * the scripts at the top of `<body>` instead, which still works; refusing to
     * emit anything would take search away from a shop for a cosmetic reason.
     *
     * IDEMPOTENT. An event row surviving an incomplete uninstall, or a theme that
     * renders the header twice, would otherwise define the config twice and load the
     * loader twice — which mounts two widgets on one input.
     *
     * @param mixed $html
     *
     * @return mixed the input unchanged when there is nothing to add
     */
    public function injectInto($html)
    {
        if (!is_string($html) || $html === '') {
            return $html;
        }

        if (strpos($html, 'window.NitroSearchConfig') !== false) {
            return $html;
        }

        $markup = $this->markup();
        if ($markup === '') {
            return $html;
        }

        $position = stripos($html, '</head>');
        if ($position === false) {
            return $html . $markup;
        }

        return substr_replace($html, $markup, $position, 0);
    }

    /**
     * Where the loader shim lives.
     *
     * NO CONSTRUCTED FALLBACK, unlike the WooCommerce plugin's. That plugin has a
     * fleet in the wild predating the setting and falls back to a frozen literal
     * path; nothing has ever installed this module without the service telling it
     * where its bundles are, and OpenCart's bundle filenames are not frozen. A
     * guessed URL here would 404 on the storefront of every shop it applied to.
     *
     * @return string
     */
    private function loaderUrl()
    {
        return (string) $this->settings->get('WIDGET_LOADER_URL');
    }
}
