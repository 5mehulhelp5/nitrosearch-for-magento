<?php
/**
 * Generate this module's storefront widget label catalogues.
 *
 * WHY THIS EXISTS. The search panel a shopper sees is rendered by one shared
 * widget bundle that serves every store on every platform, so it carries no
 * locales of its own: it renders English unless the module hands it
 * `cfg.labels` in the shop's language. WooCommerce has done that since 1.9.0,
 * PrestaShop and OpenCart since their next releases. This module has not, which
 * meant a German store view showed its shoppers an English search panel.
 *
 * WHY IT IS A DERIVATION AND NOT A TRANSLATION PROJECT. The 37 strings the
 * widget needs are the same 37 English strings on every platform — "Add to
 * cart", "In stock", "Refine results" — and they are ALREADY translated into 27
 * locales in the WooCommerce plugin's gettext catalogues, each one drafted
 * against the WordPress community glossaries, natively reviewed, and in seven
 * locales corrected by that locale's own wordpress.org translation editor.
 * Re-translating them here would produce worse text and ask volunteers to do
 * the same work twice. So this reads those catalogues and emits ours.
 *
 *   php bin/sync-widget-labels.php            # regenerate, from ../plugin
 *   php bin/sync-widget-labels.php --check    # fail if the committed files drift
 *
 * The result is committed. A merchant's shop never runs this — it runs the
 * generated PHP arrays, which is why the plural forms below are resolved HERE
 * rather than at render time. Magento's own i18n is CSV — one source string to
 * one translation, with no plural forms at all; a module needing "1 result" and
 * "5 results" is expected to branch in PHP. Resolving the CLDR categories offline
 * means the platform is never asked a question its format cannot express.
 *
 * @package NitroSearch
 */

$root = dirname(__DIR__);
$woo = $root . '/../plugin';
$widget = $root . '/../backend/widget/src/widget.jsx';
$outDir = $root . '/lib/Storefront/labels';
$check = in_array('--check', array_slice($argv, 1), true);

foreach ([$woo . '/languages', $widget] as $needed) {
    if (!file_exists($needed)) {
        fwrite(STDERR, "required source not found: $needed\n"
            . "This reads the sibling checkouts; clone them alongside this one.\n");
        exit(2);
    }
}

/**
 * The label contract, read out of the widget bundle itself.
 *
 * ⚠ NOT A LIST MAINTAINED HERE. A hand-kept copy of the widget's keys is a
 * second source of truth that drifts silently — the widget grows a key, this
 * file does not, and the new string renders in English on every non-English
 * shop while every check still passes. Parsing the bundle's own LABELS table
 * means a key we cannot resolve is a HARD FAILURE below rather than an absence.
 *
 * @return array<string,bool> key => is it a plural map
 */
function contract_keys($widgetPath)
{
    $src = (string) file_get_contents($widgetPath);
    if (!preg_match('/const LABELS = \{(.*?)\n\};/s', $src, $m)) {
        fwrite(STDERR, "could not find the LABELS table in the widget bundle — has it moved?\n");
        exit(2);
    }
    preg_match_all('/(\w+):\s*(\{[^}]*\}|\'(?:[^\'\\\\]|\\\\.)*\')/', $m[1], $mm, PREG_SET_ORDER);
    $keys = [];
    foreach ($mm as $x) {
        if ($x[2][0] === '{') {
            preg_match_all('/(\w+):\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $x[2], $pm, PREG_SET_ORDER);
            $map = [];
            foreach ($pm as $one) {
                $map[$one[1]] = stripcslashes($one[2]);
            }
            $keys[$x[1]] = $map;
        } else {
            $keys[$x[1]] = stripcslashes(substr($x[2], 1, -1));
        }
    }

    return $keys;
}

/**
 * Does this catalogue say anything the widget's built-in English does not?
 *
 * ⚠ THE ANSWER IS DERIVED, NEVER LISTED. Measured 2026-08-18, four of the five
 * English variants resolve all 37 widget strings to the source text — en_AU,
 * en_CA, en_NZ and en_ZA respell things like "colour" and "catalogue", and not
 * one of those words is a widget label. Shipping them would put 37 strings on
 * every page load to say what the bundle already says. But WRITING THAT LIST
 * DOWN would be wrong the day one of those editors changes "Add to cart", and
 * en_AU's editor is demonstrably active. So compare, and let a catalogue earn
 * its place: en_GB earns it today on one string, "Add to basket".
 */
function says_something_new(array $labels, array $contract)
{
    foreach ($labels as $key => $value) {
        $fallback = isset($contract[$key]) ? $contract[$key] : null;
        if ($fallback === null) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $category => $text) {
                $theirs = isset($fallback[$category])
                    ? $fallback[$category]
                    : (isset($fallback['other']) ? $fallback['other'] : null);
                if ($text !== $theirs) {
                    return true;
                }
            }
            continue;
        }
        if ($value !== $fallback) {
            return true;
        }
    }

    return false;
}

/**
 * Widget key => the English msgid that carries its translation.
 *
 * Mostly the key's own English text, but not always, and the exceptions are the
 * reason this is explicit rather than derived from the widget's fallback
 * strings: `powered_by` renders the brand name inline in the bundle and is
 * translated as a `%s` template; `page` and `article` are single words that
 * need a gettext CONTEXT to translate at all, because "Page" as a kind of
 * search result and "Page" as a paginator are different words in most
 * languages. Every contract key must appear here — the check below fails if one
 * does not, which is what makes a new widget key impossible to miss.
 */
const MSGIDS = [
    'refine_results' => 'Refine results',
    'refine' => 'Refine',
    'in_stock' => 'In stock',
    'on_sale' => 'On sale',
    'brand' => 'Brand',
    'category' => 'Category',
    'view' => 'View',
    'add_to_cart' => 'Add to cart',
    'adding' => 'Adding…',
    'added' => 'Added ✓',
    'try_again' => 'Try again',
    'searching' => 'Searching…',
    'unavailable_brief' => 'Search is unavailable.',
    'unavailable' => 'Search is unavailable right now.',
    'no_products' => 'No products found.',
    'no_products_for' => 'No products found for “%s”.',
    'nothing_found' => 'Nothing found for “%s”.',
    'placeholder' => 'Search products…',
    'close_search' => 'Close search',
    'product_results' => 'Product results',
    'recent_searches' => 'Recent searches',
    'clear' => 'Clear',
    'start_typing' => 'Start typing to search products…',
    'sale' => 'Sale',
    'out_of_stock' => 'Out of stock',
    'pages_posts' => 'Pages & posts',
    'page' => "a website page, shown on a search result\x04Page",
    'article' => "a blog post, shown on a search result\x04Article",
    'ms' => "unit: milliseconds\x04%s ms",
    'powered_by' => 'Powered by %s',
    'page_of' => 'Page %1$s of %2$s',
    'prev' => '← Prev',
    'next' => 'Next →',
    'products_found' => '%s product found.',
    'see_all' => 'See all %s result →',
    'results_for' => '%1$s result for “%2$s”',
    'results_count' => '%s result',
];

/**
 * Read a .po into msgid => translation, keeping plural forms as an ordered list.
 *
 * Contexted entries are keyed the way gettext keys them internally, context and
 * msgid joined by EOT — so MSGIDS above can name one without a second argument.
 *
 * @return array{messages: array<string,string|array<int,string>>, plural: string}
 */
function read_po($path)
{
    $raw = (string) file_get_contents($path);
    $messages = [];
    $pluralExpr = '';

    foreach (preg_split('/\n\n+/', $raw) as $block) {
        if (!preg_match('/^msgid ((?:"(?:[^"\\\\]|\\\\.)*"\s*)+)/m', $block, $m)) {
            continue;
        }
        $id = po_unquote($m[1]);

        if ($id === '') {
            if (preg_match('/Plural-Forms: *([^\\\\"]*)/', $block, $p)) {
                $pluralExpr = trim($p[1]);
            }
            continue;
        }

        if (preg_match('/^msgctxt ((?:"(?:[^"\\\\]|\\\\.)*"\s*)+)/m', $block, $c)) {
            $id = po_unquote($c[1]) . "\x04" . $id;
        }

        if (preg_match_all('/^msgstr\[(\d)\] ((?:"(?:[^"\\\\]|\\\\.)*"\s*)+)/m', $block, $s, PREG_SET_ORDER)) {
            $forms = [];
            foreach ($s as $one) {
                $forms[(int) $one[1]] = po_unquote($one[2]);
            }
            ksort($forms);
            $messages[$id] = array_values($forms);
        } elseif (preg_match('/^msgstr ((?:"(?:[^"\\\\]|\\\\.)*"\s*)+)/m', $block, $s)) {
            $messages[$id] = po_unquote($s[1]);
        }
    }

    return ['messages' => $messages, 'plural' => $pluralExpr];
}

function po_unquote($raw)
{
    preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $raw, $m);

    return stripcslashes(implode('', $m[1]));
}

/**
 * Which gettext plural form a locale uses at $n, from its own Plural-Forms header.
 *
 * The header is a C expression over `n`, and evaluating it is the only way to get
 * the SAME form the WooCommerce plugin would have rendered. A hand-written table
 * of "which languages have three forms" would be a second source of truth about
 * the thing this whole file exists to avoid keeping twice.
 *
 * ⚠ NOT eval(). These expressions are committed data today, but the habit of
 * eval()ing a catalogue header survives the day someone points this at a file
 * they downloaded. It is a small recursive-descent parser over the subset gettext
 * actually uses: ternary, || && == != < <= > >=, +, -, *, /, %, parentheses,
 * integers, and `n`. Anything else is refused rather than guessed at.
 *
 * @return int the zero-based msgstr[] index
 */
function plural_form($expr, $n)
{
    static $cache = [];

    if (!preg_match('/plural\s*=\s*(.+?);?\s*$/', $expr, $m)) {
        return $n === 1 ? 0 : 1;   // no header: gettext's own default
    }
    $key = $m[1] . "\x00" . $n;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $tokens = plural_tokens($m[1]);
    $pos = 0;
    $value = plural_ternary($tokens, $pos, $n);
    if ($pos !== count($tokens)) {
        fwrite(STDERR, "unparsed tail in plural expression: {$m[1]}\n");
        exit(2);
    }

    return $cache[$key] = (int) $value;
}

/** @return array<int,string> */
function plural_tokens($e)
{
    preg_match_all('/\s*(\d+|n|\|\||&&|[<>=!]=|[<>]|[-+*\/%()?:])/', $e, $m, PREG_PATTERN_ORDER);
    $tokens = $m[1];
    // Every byte must have been consumed by a token, or the expression contains
    // something this parser does not model and must not be guessed at.
    if (preg_replace('/\s+/', '', implode('', $tokens)) !== preg_replace('/\s+/', '', $e)) {
        fwrite(STDERR, "refusing to evaluate an unexpected plural expression: $e\n");
        exit(2);
    }

    return $tokens;
}

function plural_ternary($t, &$i, $n)
{
    $cond = plural_binary($t, $i, $n, 0);
    if (isset($t[$i]) && $t[$i] === '?') {
        $i++;
        $then = plural_ternary($t, $i, $n);
        if (!isset($t[$i]) || $t[$i] !== ':') {
            fwrite(STDERR, "malformed ternary in plural expression\n");
            exit(2);
        }
        $i++;
        $else = plural_ternary($t, $i, $n);

        return $cond ? $then : $else;
    }

    return $cond;
}

/** Precedence climbing, loosest level first — C's order, which gettext assumes. */
function plural_binary($t, &$i, $n, $level)
{
    static $levels = [
        ['||'],
        ['&&'],
        ['==', '!='],
        ['<', '<=', '>', '>='],
        ['+', '-'],
        ['*', '/', '%'],
    ];

    if ($level >= count($levels)) {
        return plural_atom($t, $i, $n);
    }

    $left = plural_binary($t, $i, $n, $level + 1);
    while (isset($t[$i]) && in_array($t[$i], $levels[$level], true)) {
        $op = $t[$i];
        $i++;
        $right = plural_binary($t, $i, $n, $level + 1);
        switch ($op) {
            case '||': $left = ($left || $right) ? 1 : 0; break;
            case '&&': $left = ($left && $right) ? 1 : 0; break;
            case '==': $left = ($left == $right) ? 1 : 0; break;
            case '!=': $left = ($left != $right) ? 1 : 0; break;
            case '<':  $left = ($left < $right) ? 1 : 0; break;
            case '<=': $left = ($left <= $right) ? 1 : 0; break;
            case '>':  $left = ($left > $right) ? 1 : 0; break;
            case '>=': $left = ($left >= $right) ? 1 : 0; break;
            case '+':  $left = $left + $right; break;
            case '-':  $left = $left - $right; break;
            case '*':  $left = $left * $right; break;
            case '/':  $left = $right == 0 ? 0 : intdiv((int) $left, (int) $right); break;
            case '%':  $left = $right == 0 ? 0 : (int) $left % (int) $right; break;
        }
    }

    return $left;
}

function plural_atom($t, &$i, $n)
{
    if (!isset($t[$i])) {
        fwrite(STDERR, "plural expression ended early\n");
        exit(2);
    }
    $tok = $t[$i];
    if ($tok === '(') {
        $i++;
        $v = plural_ternary($t, $i, $n);
        if (!isset($t[$i]) || $t[$i] !== ')') {
            fwrite(STDERR, "unbalanced parentheses in plural expression\n");
            exit(2);
        }
        $i++;

        return $v;
    }
    $i++;
    if ($tok === 'n') {
        return $n;
    }
    if (ctype_digit($tok)) {
        return (int) $tok;
    }
    fwrite(STDERR, "unexpected token '$tok' in plural expression\n");
    exit(2);
}

// --- Resolve the contract, then every locale ---------------------------------

$keys = contract_keys($widget);
$missing = array_diff(array_keys($keys), array_keys(MSGIDS));
if ($missing) {
    fwrite(STDERR, "the widget declares label key(s) this generator cannot resolve: "
        . implode(', ', $missing) . "\nAdd them to MSGIDS with the English msgid that carries them.\n");
    exit(1);
}

// The four CLDR categories the widget asks for, and the count that selects each
// one. 'other' samples at 100, NOT 5: Romanian's "few" form covers 2-19, so a
// count of 5 would freeze the few-form into the category CLDR only selects from
// 20 upward — the exact bug the WooCommerce resolver documents.
$samples = ['one' => 1, 'few' => 2, 'many' => 5, 'other' => 100];

$catalogues = [];
$silent = [];
foreach (glob($woo . '/languages/nitrosearch-*.po') as $po) {
    $locale = preg_replace('/^nitrosearch-|\.po$/', '', basename($po));

    if ($locale === 'en_US') {
        continue;   // the source itself
    }

    $po = read_po($po);
    $out = [];
    $gaps = [];

    foreach ($keys as $key => $fallback) {
        $isPlural = is_array($fallback);
        $id = MSGIDS[$key];
        $value = isset($po['messages'][$id]) ? $po['messages'][$id] : null;

        if ($isPlural) {
            if (!is_array($value) || $value === []) {
                $gaps[] = $key;
                continue;
            }
            $map = [];
            foreach ($samples as $category => $n) {
                $form = plural_form($po['plural'], $n);
                $text = isset($value[$form]) ? $value[$form] : end($value);
                if ($text !== '') {
                    $map[$category] = $text;
                }
            }
            // A locale with one form for every count produces four identical
            // entries; collapse to `other` so the file says what it means.
            if (count(array_unique($map)) === 1) {
                $map = ['other' => reset($map)];
            }
            $out[$key] = $map;
            continue;
        }

        if (!is_string($value) || $value === '') {
            $gaps[] = $key;
            continue;
        }
        // 'powered_by' is a template in gettext and a finished string on the
        // wire — the widget renders it verbatim.
        $out[$key] = $key === 'powered_by' ? sprintf($value, 'NitroSearch') : $value;
    }

    if ($gaps) {
        fwrite(STDERR, "$locale is missing " . count($gaps) . " widget string(s): "
            . implode(', ', $gaps) . "\n");
        exit(1);
    }

    if (!says_something_new($out, $keys)) {
        $silent[] = $locale;
        continue;
    }

    $catalogues[$locale] = $out;
}

if ($silent) {
    fwrite(STDOUT, "  --   identical to the widget's own English, so not shipped: "
        . implode(', ', $silent) . "\n");
}

ksort($catalogues);

// --- Emit --------------------------------------------------------------------

if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$drift = [];
foreach ($catalogues as $locale => $labels) {
    $php = "<?php\n"
        . "/**\n"
        . " * Storefront widget labels — " . $locale . ".\n"
        . " *\n"
        . " * GENERATED by bin/sync-widget-labels.php from the reviewed gettext\n"
        . " * catalogues in the WooCommerce plugin. Do not edit by hand: the next\n"
        . " * run overwrites it, and a hand edit here is a correction that never\n"
        . " * reaches the other platforms. Fix the .po and regenerate.\n"
        . " *\n"
        . " * @package NitroSearch\n"
        . " */\n\n"
        . "return " . var_export($labels, true) . ";\n";

    $path = $outDir . '/' . $locale . '.php';
    $existing = file_exists($path) ? (string) file_get_contents($path) : null;

    if ($check) {
        if ($existing !== $php) {
            $drift[] = $locale;
        }
        continue;
    }

    if ($existing !== $php) {
        file_put_contents($path, $php);
    }
}

if ($check) {
    // A catalogue for a locale that no longer exists upstream is drift too.
    foreach (glob($outDir . '/*.php') as $have) {
        $locale = basename($have, '.php');
        if (!isset($catalogues[$locale])) {
            $drift[] = $locale . ' (no longer upstream)';
        }
    }
    if ($drift) {
        fwrite(STDERR, "committed catalogues differ from the source: " . implode(', ', $drift)
            . "\nRun: php bin/sync-widget-labels.php\n");
        exit(1);
    }
    echo "ok   " . count($catalogues) . " catalogue(s) match the reviewed sources\n";
    exit(0);
}

// NO index.php GUARD HERE, unlike the PrestaShop generator this is ported from.
// That is PrestaShop's convention for making a directory unbrowsable; nothing in
// this module's lib/ carries one, and on OpenCart writing one produced a file the
// resolver then tried to read as a catalogue.

echo "wrote " . count($catalogues) . " catalogue(s) to lib/Storefront/labels/ ("
    . count($keys) . " keys each)\n";
