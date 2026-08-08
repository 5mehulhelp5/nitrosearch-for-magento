#!/usr/bin/env bash
#
# Refuse to ship a module that collects search usage a merchant cannot decline.
#
# WHY THIS EXISTS, AND IT IS SOMEONE ELSE'S SCAR. The storefront widget emits an
# anonymous usage beacon, and the merchant's control over it is one key in the config
# the module injects: `cfg.analytics`. The widget declines ONLY on an explicit
# `cfg.analytics === false`.
#
# THE FAILURE MODE IS AN ABSENT KEY, NOT A WRONG ONE. An omitted key arrives as
# `undefined`, and `undefined !== false`, so leaving it out means always-on. The
# OpenCart connector shipped 1.0.0 and 1.1.0 in exactly that state: no setting, the key
# never sent, and the service issues an events token to every verified store — so there
# was no layer at which a merchant could say no. Nothing failed, nothing logged, and no
# amount of using the module would have revealed it, because a control that was never
# built has no broken behaviour to observe.
#
# SO THE CHECK IS FOR PRESENCE ACROSS THE WHOLE CHAIN — the admin field that lets a
# merchant set it, the default that decides what an untouched store sends, and the
# config blob that actually carries it. An omission anywhere in that chain is
# invisible, and each link is checked separately because any one of them can be
# missing while the other two look right.
#
#   bin/check-analytics-optout.sh              # check the working tree
#   bin/check-analytics-optout.sh --self-test  # prove the check still bites
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

fail() { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
pass() { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }

check_tree() {
    local root="${1:-$ROOT}"

    # 1. The merchant-facing control.
    grep -q 'share_search_data' "$root/etc/adminhtml/system.xml" 2>/dev/null \
        || { echo "no share_search_data field on the admin screen — a merchant cannot decline"; return 1; }

    # 2. The default an untouched store carries. Absent means null, which the
    #    settings layer reads as '' — falsy — so a fresh store would silently
    #    opt OUT, which is the opposite failure and just as wrong.
    grep -q '<share_search_data>' "$root/etc/config.xml" 2>/dev/null \
        || { echo "share_search_data has no default in etc/config.xml"; return 1; }

    # 3. The key must actually reach the blob. The vendored widget builder is what
    #    emits it, and it must send the key even when TRUE — a value inferred from
    #    silence is exactly the bug this guard exists for.
    grep -q "'analytics'" "$root/lib/Storefront/Widget.php" 2>/dev/null \
        || { echo "the config blob does not carry an 'analytics' key"; return 1; }

    # 4. And the order report must honour it too. Revenue is usage data; a merchant
    #    who declined analytics must not have their orders reported anyway.
    grep -q 'SHARE_SEARCH_DATA' "$root/Model/OrderAttribution.php" 2>/dev/null \
        || { echo "order attribution does not check SHARE_SEARCH_DATA"; return 1; }

    return 0
}

if [ "${1:-}" = "--self-test" ]; then
    tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' EXIT
    mkdir -p "$tmp/etc/adminhtml" "$tmp/lib/Storefront" "$tmp/Model"
    # A tree with the admin field removed and everything else intact — the exact
    # shape OpenCart shipped twice.
    grep -v 'share_search_data' "$ROOT/etc/adminhtml/system.xml" > "$tmp/etc/adminhtml/system.xml"
    cp "$ROOT/etc/config.xml" "$tmp/etc/config.xml"
    cp "$ROOT/lib/Storefront/Widget.php" "$tmp/lib/Storefront/Widget.php"
    cp "$ROOT/Model/OrderAttribution.php" "$tmp/Model/OrderAttribution.php"

    if check_tree "$tmp" >/dev/null 2>&1; then
        fail "self-test: the guard PASSED a tree with no merchant control"
    fi
    if ! check_tree "$ROOT" >/dev/null 2>&1; then
        fail "self-test: the guard REFUSED the real tree"
    fi
    pass "self-test: refuses a module with no opt-out, accepts the real one"
    exit 0
fi

if out="$(check_tree "$ROOT")"; then
    pass "a merchant can decline usage sharing, and the choice reaches the wire"
else
    fail "$out"
fi
