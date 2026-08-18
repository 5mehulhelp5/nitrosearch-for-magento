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

/**
 * The storefront search panel's own strings, in the shop's language.
 *
 * WHAT THIS FIXES. The panel a shopper sees is drawn by one shared widget bundle
 * that serves every store on every platform, so it carries no locales: it renders
 * its built-in English unless the module hands it `cfg.labels`. A German store
 * view, with a German admin and a German theme, showed its shoppers "Add to cart",
 * "In stock" and "No products found." `Widget::config()` carried a comment saying
 * the key was omitted DELIBERATELY because this module had no catalogues to send.
 * That was true and is no longer.
 *
 * WHERE THE WORDS COME FROM. `bin/sync-widget-labels.php` derives the catalogues
 * in `labels/` from the WooCommerce plugin's gettext catalogues — the same 37
 * English strings, already translated into 23 shipping locales, already natively
 * reviewed, and in seven of them corrected by that locale's own wordpress.org
 * translation editor. They are generated and committed; a shop never runs the
 * generator.
 *
 * WHY NOT MAGENTO'S OWN i18n. Because it cannot express this. Magento translates
 * through CSV — one source string to one translation, with no plural forms at all,
 * so a module needing "1 result" and "5 results" branches in PHP — while the widget
 * needs four CLDR categories per plural string, chosen by the browser at render
 * time. Asking the platform a question its format cannot express, to arrive at text
 * we already have, would be the long way round to a worse answer.
 *
 * FRAMEWORK-FREE, LIKE EVERYTHING IN lib/. It reads its own directory and knows
 * nothing about Magento.
 */
class Labels
{
    /**
     * The language the widget bundle is already written in.
     *
     * ⚠ THIS IS WHY ENGLISH NEVER FALLS BACK BY LANGUAGE. Every other language
     * may: a de-at shop reading the de_DE catalogue, or fr-be reading fr_FR, is
     * unambiguously better off than reading English. English is the one case
     * where the bundle's own text is already right for most regions and the
     * catalogue we ship is the exception — en_GB exists to say "Add to basket",
     * a word en_AU, en_CA, en_NZ and en_ZA all measurably reject. Falling en-au
     * back to en_GB would not be a near-miss; it would replace correct text with
     * wrong text.
     */
    const SOURCE_LANGUAGE = 'en';

    /**
     * @param string $locale the store view's `general/locale/code`. Magento
     *                       writes these in the catalogue's own spelling —
     *                       `de_DE`, `pt_BR` — which makes most of them a
     *                       straight match, and makes the nine that are NOT
     *                       easy to miss. See catalogueFor().
     *
     * @return array<string, string|array<string, string>> empty when we have
     *                                                     nothing better than
     *                                                     the widget's English
     */
    public static function forLocale($locale)
    {
        $name = self::catalogueFor($locale);
        if ($name === null) {
            return array();
        }

        $labels = include __DIR__ . '/labels/' . $name . '.php';

        return is_array($labels) ? $labels : array();
    }

    /**
     * Which shipped catalogue serves this locale, if any.
     *
     * @param string $locale
     *
     * @return string|null
     */
    public static function catalogueFor($locale)
    {
        $normalised = str_replace('-', '_', trim((string) $locale));

        // ⚠ MAGENTO SHIPS NINE LOCALES WITH A SCRIPT SUBTAG, and a two-part
        // pattern rejects every one of them silently. Measured on 2.4.8: of 92
        // allowed locales, `az_Latn_AZ`, `bs_Latn_BA`, `mn_Cyrl_MN`, `ms_Latn_MY`,
        // `sr_Cyrl_RS`, `sr_Latn_RS`, `zh_Hans_CN`, `zh_Hant_HK` and `zh_Hant_TW`
        // carry one. The one that costs us is `zh_Hans_CN`: a Simplified Chinese
        // store, for which we HAVE a catalogue, would have been handed nothing.
        if (!preg_match('/^([a-zA-Z]{2,3})(?:_([a-zA-Z]{4}))?(?:_([a-zA-Z0-9]{2,4}))?$/', $normalised, $m)) {
            return null;
        }

        $language = strtolower($m[1]);
        $script = isset($m[2]) ? $m[2] : '';
        $region = isset($m[3]) && $m[3] !== '' ? strtoupper($m[3]) : '';
        $shipped = self::shipped();

        // Exact first: pt_BR and pt_PT are different catalogues and neither may
        // stand in for the other.
        $exact = $region === '' ? $language : $language . '_' . $region;
        foreach ($shipped as $name) {
            if (strcasecmp($name, $exact) === 0) {
                return $name;
            }
        }

        if ($language === self::SOURCE_LANGUAGE) {
            return null;   // see SOURCE_LANGUAGE
        }

        // ⚠ A SCRIPT THAT DID NOT MATCH EXACTLY BLOCKS THE LANGUAGE FALLBACK, for
        // the same reason English does. `zh_Hant_TW` is Traditional Chinese and the
        // only `zh` catalogue we ship is Simplified — so "exactly one catalogue for
        // this language" would hand a Taiwanese shopper a script they do not read.
        // The subtag exists precisely to say "this is a different written form", so
        // when it is present and the exact match failed, English is the honest
        // answer rather than the near one.
        if ($script !== '') {
            return null;
        }

        // Otherwise the language alone, but only when exactly one catalogue
        // claims it. Two would be a guess between regions, and a guess here
        // reads as a translation error rather than a missing translation.
        $candidates = array();
        foreach ($shipped as $name) {
            $head = strpos($name, '_') === false ? $name : substr($name, 0, strpos($name, '_'));
            if (strcasecmp($head, $language) === 0) {
                $candidates[] = $name;
            }
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * The catalogues actually present on disk.
     *
     * Read from the directory rather than declared in a list here: the generator
     * decides which locales earn a catalogue — one that resolves every string to
     * the widget's own English is not shipped — and a second list in this file
     * would be wrong the first time that set changed.
     *
     * @return array<int, string>
     */
    public static function shipped()
    {
        static $names = null;
        if ($names !== null) {
            return $names;
        }

        // Only names that ARE locales. Excluding one known filename would have
        // been enough today and wrong the next time something else lands in this
        // directory — an editor backup, a platform's own guard file. The port
        // from PrestaShop wrote an `index.php` here and the resolver dutifully
        // offered it as a catalogue, which is the whole argument.
        $names = array();
        foreach ((array) glob(__DIR__ . '/labels/*.php') as $path) {
            $name = basename($path, '.php');
            if (preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $name)) {
                $names[] = $name;
            }
        }
        sort($names);

        return $names;
    }
}
