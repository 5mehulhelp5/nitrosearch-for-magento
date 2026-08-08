#!/usr/bin/env bash
#
# Refuse to ship a CSP contribution that names a host instead of deriving it.
#
# THE SCAR. `etc/csp_whitelist.xml` is static and carries `cdn.nitrosearch.io` and
# `api.nitrosearch.io`. `Model\CspPolicy` was written because the Typesense engine host
# is per-store and cannot live in a static file — the reasoning was spelled out at
# length in that class — and then the widget's own script host and the analytics
# endpoint, which are ALSO handed to each store by the service, were left to the
# literals.
#
# Nothing revealed it, because a stock Magento storefront ships CSP in REPORT-ONLY
# mode: every violation is logged and none is enforced. **Installing Hyvä is what
# exposed it** — a strict-CSP storefront refuses the loader outright, the widget never
# appears, and the only trace is a console message on the shopper's own machine. Same
# class as [D-059], where a store's widget URL was a developer's laptop and no deploy
# check ever looked at the address it was handing out.
#
# SO EVERY HOST THE WIDGET TALKS TO MUST BE DERIVED FROM WHAT THAT STORE WAS SENT.
# The static file stays as a floor for a store that has connected but not yet been
# handed its URLs; it must never be the only source.
#
#   bin/check-csp-hosts.sh              # check the working tree
#   bin/check-csp-hosts.sh --self-test  # prove the check still bites
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

fail() { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
pass() { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }

# Every setting the SERVICE hands a store that a browser then has to be allowed to
# reach. Derived from what the widget config blob carries, not from memory: the
# storefront builder emits exactly these, and a new one added there without one added
# here is the failure this guard exists for.
REQUIRED_SETTINGS="ENGINE_HOST EVENTS_URL WIDGET_LOADER_URL WIDGET_BUNDLE_URL"

check_tree() {
    local root="${1:-$ROOT}"
    local policy="$root/Model/CspPolicy.php"

    [ -f "$policy" ] || { echo "no Model/CspPolicy.php — every NitroSearch host would come from the static whitelist"; return 1; }

    local missing=""
    local key
    for key in $REQUIRED_SETTINGS; do
        grep -q "'$key'" "$policy" || missing="$missing $key"
    done

    if [ -n "$missing" ]; then
        echo "CspPolicy never reads:$missing — those hosts would fall back to a hardcoded literal, and a store on any other host is blocked with only a console message"
        return 1
    fi

    # Both directives, because allowing the engine to be reached while the script that
    # reaches it is refused is the failure with the fewest symptoms.
    grep -q "'connect-src'" "$policy" \
        || { echo "CspPolicy adds nothing to connect-src"; return 1; }
    grep -q "'script-src'" "$policy" \
        || { echo "CspPolicy adds nothing to script-src — the loader itself is what a strict policy refuses first"; return 1; }

    # The static file is a floor, not the source. If it ever grows a per-store-looking
    # host, somebody has answered a runtime question in a compile-time file.
    local whitelist="$root/etc/csp_whitelist.xml"
    if [ -f "$whitelist" ] && grep -qE '<value[^>]*>[^<]*(localhost|[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+|:[0-9]{2,5})' "$whitelist"; then
        echo "etc/csp_whitelist.xml names a host that looks per-store or per-environment — that belongs in CspPolicy"
        return 1
    fi

    return 0
}

if [ "${1:-}" = "--self-test" ]; then
    tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' EXIT
    mkdir -p "$tmp/Model" "$tmp/etc"
    cp "$ROOT/etc/csp_whitelist.xml" "$tmp/etc/csp_whitelist.xml"

    # (a) The shape that shipped: the engine host derived, everything else static.
    sed -e "s/'WIDGET_LOADER_URL', 'WIDGET_BUNDLE_URL'/'ENGINE_HOST'/" \
        -e "s/'ENGINE_HOST', 'EVENTS_URL'/'ENGINE_HOST'/" \
        "$ROOT/Model/CspPolicy.php" > "$tmp/Model/CspPolicy.php"

    if check_tree "$tmp" >/dev/null 2>&1; then
        fail "self-test: the guard PASSED a policy that only derives the engine host"
    fi

    # (b) All four read, but only connect-src contributed — the loader still refused.
    grep -v "'script-src'" "$ROOT/Model/CspPolicy.php" > "$tmp/Model/CspPolicy.php"

    if check_tree "$tmp" >/dev/null 2>&1; then
        fail "self-test: the guard PASSED a policy that never contributes to script-src"
    fi

    # (c) A per-environment host smuggled into the static file.
    cp "$ROOT/Model/CspPolicy.php" "$tmp/Model/CspPolicy.php"
    sed 's#<value id="nitrosearch_cdn" type="host">cdn.nitrosearch.io</value>#<value id="nitrosearch_cdn" type="host">localhost:8000</value>#' \
        "$ROOT/etc/csp_whitelist.xml" > "$tmp/etc/csp_whitelist.xml"

    if check_tree "$tmp" >/dev/null 2>&1; then
        fail "self-test: the guard PASSED a static whitelist naming a per-environment host"
    fi

    if ! check_tree "$ROOT" >/dev/null 2>&1; then
        fail "self-test: the guard REFUSED the real tree"
    fi

    pass "self-test: refuses a hardcoded host, a missing script-src, and a per-environment literal"
    exit 0
fi

if out="$(check_tree "$ROOT")"; then
    pass "every host the widget reaches is derived from what the service sent this store"
else
    fail "$out"
fi
