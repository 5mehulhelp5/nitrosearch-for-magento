#!/usr/bin/env bash
#
# Refuse to ship a config blob that can break out of its own element.
#
# WHY THIS EXISTS, AND IT IS INHERITED RATHER THAN INVENTED. The storefront widget is
# configured by JSON written into the page. A `</script>` anywhere inside that JSON
# closes the element early and everything after it becomes live markup — and a
# merchant-supplied CSS selector reaches the object as free text, so "nothing will
# contain one" is a hope rather than a property.
#
# WHY A GREP AND NOT A CODE REVIEW. The correct version and the broken version look
# identical on the page:
#
#     $json = str_replace('<', '<', $json);
#
# The needle and the replacement are the same byte. That compiles, runs, escapes
# nothing, and reads exactly like a version that works. There is nothing on the line
# for a reviewer to catch, because the bug is that two strings are equal.
#
# MAGENTO'S VERSION OF THE PROBLEM IS SMALLER AND THE GUARD IS STILL WORTH HAVING.
# The blob ships as `<script type="application/json">` rather than an inline script,
# so JSON in a non-executable type is data — but `</script>` still terminates the
# element whatever its type, so the escaping is exactly as load-bearing here.
#
#   bin/check-script-escaping.sh              # check the working tree
#   bin/check-script-escaping.sh --self-test  # prove the check still bites
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

fail() { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
pass() { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }

# The one place the blob is encoded.
BLOCK="$ROOT/Block/Storefront/Config.php"

check_tree() {
    local root="${1:-$ROOT}"
    local block="$root/Block/Storefront/Config.php"

    [ -f "$block" ] || { echo "missing $block"; return 1; }

    # The encoder must pass JSON_HEX_TAG. Nothing else turns < and > into escapes,
    # and it is the only flag that closes the element-termination hole.
    grep -q 'JSON_HEX_TAG' "$block" || { echo "Block/Storefront/Config.php does not pass JSON_HEX_TAG"; return 1; }

    # A same-byte str_replace anywhere near the encoding is the invisible bug above.
    if grep -nE "str_replace\(\s*'<'\s*,\s*'<'" "$block" >/dev/null 2>&1; then
        echo "a same-byte str_replace is present — it escapes nothing and reads as if it does"
        return 1
    fi

    # The template must not echo the blob through anything that re-encodes or
    # double-escapes it; @noEscape is the declared, reviewed exemption.
    local tpl="$root/view/frontend/templates/storefront/config.phtml"
    [ -f "$tpl" ] || { echo "missing $tpl"; return 1; }
    grep -q 'noEscape' "$tpl" || { echo "config.phtml does not declare its escaping intent"; return 1; }

    return 0
}

if [ "${1:-}" = "--self-test" ]; then
    # PROVE IT BITES. A copy with the flag removed must be refused; the real tree
    # must pass. One direction alone is not a self-test — a check that always fails
    # would satisfy the first half.
    tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' EXIT
    mkdir -p "$tmp/Block/Storefront" "$tmp/view/frontend/templates/storefront"
    sed 's/JSON_HEX_TAG/JSON_UNESCAPED_UNICODE/' "$BLOCK" > "$tmp/Block/Storefront/Config.php"
    cp "$ROOT/view/frontend/templates/storefront/config.phtml" "$tmp/view/frontend/templates/storefront/config.phtml"

    if check_tree "$tmp" >/dev/null 2>&1; then
        fail "self-test: the guard PASSED a tree with JSON_HEX_TAG removed"
    fi
    if ! check_tree "$ROOT" >/dev/null 2>&1; then
        fail "self-test: the guard REFUSED the real tree"
    fi
    pass "self-test: refuses an unescaped blob, accepts the real one"
    exit 0
fi

if out="$(check_tree "$ROOT")"; then
    pass "the config blob cannot break out of its element"
else
    fail "$out"
fi
