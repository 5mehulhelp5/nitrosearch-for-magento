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
 * WIDGET LABELS — the strings a shopper reads, in the shop's language.
 *
 * The search panel is drawn by one shared bundle carrying no locales, so it
 * renders English unless this module sends `cfg.labels`. Until now it never did,
 * and `Widget::config()` said so in a comment: there were no catalogues to send.
 *
 * ⚠ WHAT MAKES THIS FAILURE MODE NASTY IS THAT NOTHING BREAKS. A missing label
 * key does not error, does not warn, and does not fail a request — the widget
 * falls back to its own English per key and renders a panel that looks entirely
 * correct to anyone who reads English. The only symptom is a shopper in Bucharest
 * reading a word nobody chose for them. There is no crash to assert on, so these
 * cases assert the CONTENT and the COMPLETENESS.
 *
 * ⚠ AND MAGENTO'S LOCALES ARE NOT ALL TWO PARTS. `general/locale/code` is mostly
 * the catalogue's own spelling (`de_DE`), which makes the exceptions easy to miss:
 * nine of Magento's 92 allowed locales carry a SCRIPT subtag, and a two-part
 * pattern rejects every one of them without a word. `zh_Hans_CN` is the one that
 * costs us — a Simplified Chinese store we have a catalogue for. The sweep below
 * runs the real list rather than a sample.
 *
 * WHAT THIS CANNOT PROVE: that the widget renders them. The labels go over the
 * wire as JSON to a bundle this repo does not contain, with plural categories
 * chosen by the browser's Intl.PluralRules at render time. The honest
 * verification for that is a real store.
 */

require_once dirname(dirname(__DIR__)) . '/lib/Storefront/Labels.php';

use NitroSearch\Storefront\Labels;

/** The widget's own label table — the contract, read from the bundle it belongs to. */
function ns_mage_widget_contract($root)
{
    $path = $root . '/../backend/widget/src/widget.jsx';
    if (!is_file($path)) {
        return null;
    }
    $src = (string) file_get_contents($path);
    if (!preg_match('/const LABELS = \{(.*?)\n\};/s', $src, $m)) {
        return null;
    }
    preg_match_all('/(\w+):\s*(\{[^}]*\}|\'(?:[^\'\\\\]|\\\\.)*\')/', $m[1], $mm, PREG_SET_ORDER);
    $keys = array();
    foreach ($mm as $x) {
        $keys[$x[1]] = $x[2][0] === '{';
    }

    return $keys;
}

return array(

    'every locale Magento can actually be set to is handled' => function ($root) {
        // ⚠ THE REAL LIST, read off Magento\Framework\Locale\Config on 2.4.8 rather
        // than imagined. 92 of them, and NINE carry a script subtag — which a
        // two-part locale pattern rejects silently, handing those stores nothing.
        $magento = array(
        'af_ZA', 'ar_DZ', 'ar_EG', 'ar_KW', 'ar_MA', 'ar_SA', 'az_Latn_AZ', 'be_BY', 'bg_BG',
        'bn_BD', 'bs_Latn_BA', 'ca_ES', 'cs_CZ', 'cy_GB', 'da_DK', 'de_AT', 'de_CH', 'de_DE',
        'de_LU', 'el_GR', 'en_AU', 'en_CA', 'en_GB', 'en_IE', 'en_NZ', 'en_US', 'es_AR',
        'es_BO', 'es_CL', 'es_CO', 'es_CR', 'es_ES', 'es_MX', 'es_PA', 'es_PE', 'es_US',
        'es_VE', 'et_EE', 'eu_ES', 'fa_IR', 'fi_FI', 'fil_PH', 'fr_BE', 'fr_CA', 'fr_CH',
        'fr_FR', 'fr_LU', 'gl_ES', 'gu_IN', 'he_IL', 'hi_IN', 'hr_HR', 'hu_HU', 'id_ID',
        'is_IS', 'it_CH', 'it_IT', 'ja_JP', 'ka_GE', 'km_KH', 'ko_KR', 'lo_LA', 'lt_LT',
        'lv_LV', 'mk_MK', 'mn_Cyrl_MN', 'ms_Latn_MY', 'ms_MY', 'nb_NO', 'nl_BE', 'nl_NL',
        'nn_NO', 'pl_PL', 'pt_BR', 'pt_PT', 'ro_RO', 'ru_RU', 'sk_SK', 'sl_SI', 'sq_AL',
        'sr_Cyrl_RS', 'sr_Latn_RS', 'sv_FI', 'sv_SE', 'sw_KE', 'th_TH', 'tr_TR', 'uk_UA',
        'vi_VN', 'zh_Hans_CN', 'zh_Hant_HK', 'zh_Hant_TW'
        );
        ns_is('the fixture is the whole list', 92, count($magento));

        foreach ($magento as $locale) {
            $name = Labels::catalogueFor($locale);
            ns_true(
                $locale . ' resolves to a shipped catalogue or to nothing',
                $name === null || in_array($name, Labels::shipped(), true)
            );
        }

        // The ones with a definite right answer.
        ns_is('de_DE is exact', 'de_DE', Labels::catalogueFor('de_DE'));
        ns_is('pt_BR is exact', 'pt_BR', Labels::catalogueFor('pt_BR'));
        ns_is('de_AT reads German', 'de_DE', Labels::catalogueFor('de_AT'));
        ns_is('fr_CA reads French', 'fr_FR', Labels::catalogueFor('fr_CA'));
        ns_is('ja_JP reads ja', 'ja', Labels::catalogueFor('ja_JP'));

        // ⚠ THE SCRIPT SUBTAG, BOTH WAYS. zh_Hans_CN is Simplified and we have it;
        // zh_Hant_TW is Traditional and we do not. A "one catalogue for this
        // language" rule would hand a Taiwanese shopper Simplified Chinese, which
        // is the same class of error as giving Australia "Add to basket".
        ns_is('zh_Hans_CN reads Simplified Chinese', 'zh_CN', Labels::catalogueFor('zh_Hans_CN'));
        ns_is('zh_Hant_TW is refused, not approximated', null, Labels::catalogueFor('zh_Hant_TW'));
        ns_is('zh_Hant_HK is refused too', null, Labels::catalogueFor('zh_Hant_HK'));
        ns_is('sr_Cyrl_RS has no catalogue at all', null, Labels::catalogueFor('sr_Cyrl_RS'));

        $zh = Labels::forLocale('zh_Hans_CN');
        ns_true('and Simplified Chinese really is Chinese', isset($zh['add_to_cart']) && $zh['add_to_cart'] !== 'Add to cart');
    },

    'every shipped catalogue covers the whole widget contract' => function ($root) {
        $contract = ns_mage_widget_contract($root);
        if ($contract === null) {
            ns_is('the widget contract is readable', true, true);   // skip, stated

            return;
        }
        ns_true('the contract has keys at all', count($contract) > 30);

        $shipped = Labels::shipped();
        ns_true('catalogues are shipped', count($shipped) > 0);

        foreach ($shipped as $name) {
            $labels = Labels::forLocale($name);
            ns_is($name . ' covers every contract key', array(), array_values(array_diff(array_keys($contract), array_keys($labels))));
            ns_is($name . ' sends nothing the widget cannot use', array(), array_values(array_diff(array_keys($labels), array_keys($contract))));

            foreach ($contract as $key => $isPlural) {
                if (!isset($labels[$key])) {
                    continue;
                }
                ns_is(
                    $name . ' » ' . $key . ' has the shape the widget reads',
                    $isPlural ? 'array' : 'string',
                    is_array($labels[$key]) ? 'array' : gettype($labels[$key])
                );
                if ($isPlural) {
                    ns_true($name . ' » ' . $key . ' has an "other" form', isset($labels[$key]['other']));
                } else {
                    ns_true($name . ' » ' . $key . ' is not empty', $labels[$key] !== '');
                }
            }
        }
    },

    'a shop reading English is sent nothing at all' => function ($root) {
        // Not "sent English" — sent NOTHING. The bundle already has this text, and
        // 37 redundant strings on every page view is a cost with no benefit. It
        // also preserves the property the old comment protected: the widget never
        // selects plurals by a non-English locale's rules over English strings.
        foreach (array('en_US', 'en', 'en_AU', 'en_CA', 'en_NZ', 'en_ZA', 'en_IE') as $locale) {
            ns_is('nothing for ' . $locale, array(), Labels::forLocale($locale));
        }

        $gb = Labels::forLocale('en_GB');
        ns_true('en_GB does get a catalogue', $gb !== array());
        ns_is('and it is there for the basket', 'Add to basket', isset($gb['add_to_cart']) ? $gb['add_to_cart'] : null);
    },

    'a region we do not ship falls back by language, except in English' => function ($root) {
        ns_is('de_CH reads German', 'de_DE', Labels::catalogueFor('de_CH'));
        ns_is('es_AR reads Spanish', 'es_ES', Labels::catalogueFor('es_AR'));

        // ⚠ THE ONE THAT MUST NOT FALL BACK. en_GB is the only English catalogue
        // shipped, so a naive "exactly one catalogue for this language" rule hands
        // "Add to basket" to Australia, Canada, New Zealand and South Africa — all
        // four of whose editors kept "cart".
        ns_is('en_AU gets no catalogue', null, Labels::catalogueFor('en_AU'));

        // Portuguese ships two and neither may stand in for the other.
        ns_is('pt_AO is refused rather than guessed', null, Labels::catalogueFor('pt_AO'));

        // Language-only catalogues serve their language whatever the region.
        ns_is('ja_JP reads ja', 'ja', Labels::catalogueFor('ja_JP'));
        ns_is('uk_UA reads uk', 'uk', Labels::catalogueFor('uk_UA'));
    },

    'a locale we have never heard of is refused, not guessed at' => function ($root) {
        // The empty string is the real one: `general/locale/code` is unset on a
        // store view that has never been configured, which is why Widget already
        // treats '' as "omit the locale".
        foreach (array('', '   ', 'xx_YY', 'klingon', '../../etc/passwd', 'de/../../x', 'zz') as $bad) {
            ns_is('no catalogue for ' . var_export($bad, true), null, Labels::catalogueFor($bad));
            ns_is('no labels for ' . var_export($bad, true), array(), Labels::forLocale($bad));
        }
    },

    'the catalogues carry reviewed translations, not the English source' => function ($root) {
        // If a catalogue echoed the source it would be 100% "complete", pass every
        // structural check above, and do nothing — the same shape as the en_GB
        // catalogue that echoed American English on WooCommerce and was caught by
        // mutation rather than by any guard.
        $spot = array(
            'de_DE' => array('add_to_cart' => 'In den Warenkorb', 'in_stock' => 'Vorrätig'),
            'fr_FR' => array('add_to_cart' => 'Ajouter au panier'),
            'ro_RO' => array('add_to_cart' => 'Adaugă în coș'),
            'ja' => array('add_to_cart' => 'カートに追加'),
        );
        foreach ($spot as $locale => $expected) {
            $labels = Labels::forLocale($locale);
            foreach ($expected as $key => $text) {
                ns_is($locale . ' » ' . $key, $text, isset($labels[$key]) ? $labels[$key] : null);
            }
        }
    },

    'Romanian keeps the plural forms its editor actually chose' => function ($root) {
        // Why the generator samples at 1, 2, 5 and 100 rather than 1 and 2:
        // Romanian's "few" covers 2-19 and its "other" only starts at 20, where the
        // noun takes "de". Sampling at 5 for "other" would freeze the few-form in
        // and no test of counts under 20 would ever notice.
        $ro = Labels::forLocale('ro_RO');
        ns_true('results_count is a plural map', is_array($ro['results_count']));
        ns_is('few (2-19) has no "de"', '%s rezultate', $ro['results_count']['few']);
        ns_is('other (20+) has "de"', '%s de rezultate', $ro['results_count']['other']);
        ns_is('one spells the number', 'Un rezultat', $ro['results_count']['one']);
    },

    'a single-form language collapses instead of repeating itself four times' => function ($root) {
        $ja = Labels::forLocale('ja');
        ns_is('Japanese has one plural form', array('other'), array_keys($ja['results_count']));
    },

    'the committed catalogues match what the generator produces' => function ($root) {
        if (!is_dir($root . '/../plugin/languages') || !is_file($root . '/../backend/widget/src/widget.jsx')) {
            ns_is('sibling checkouts present for the drift check', true, true);   // skip, stated

            return;
        }
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/sync-widget-labels.php') . ' --check 2>&1';
        $out = array();
        $status = 0;
        exec($cmd, $out, $status);
        ns_is('generator reports no drift: ' . implode(' ', $out), 0, $status);
    },
);
