#!/usr/bin/env bash
#
# Refuse to ship a module that declares another platform's slug.
#
# WHY THIS EXISTS, AND IT IS THIS MODULE'S OWN SCAR RATHER THAN AN INHERITED ONE.
#
# `lib/` is vendored byte-identically from the OpenCart connector so the canonical
# signing string cannot drift between platforms. That copy had `'platform' => 'opencart'`
# HARDCODED in `Api\Client::connect()`, and it survived into this module unnoticed
# through a code read.
#
# WHAT IT COST, HAD IT SHIPPED: the service registers a Magento store as OpenCart and
# hands back the OPENCART widget bundle — whose loader reads `search` as the query
# parameter where Magento uses `q`, and whose cart endpoint is a route Magento does not
# have. A storefront that renders the entire catalogue under every search term, which is
# precisely what [D-039] cost the OpenCart connector by a different route.
#
# WHY NOTHING ELSE CATCHES IT. Every request signs correctly and succeeds. The module
# connects, verifies, syncs. The only place the wrong value is visible is the SERVICE's
# own record of the store — so it is invisible from inside the module by construction,
# and a code review of a file whose comments talk fluently about OpenCart reads as
# correct because it is correct, for OpenCart.
#
#   bin/check-platform-slug.sh              # check the working tree
#   bin/check-platform-slug.sh --self-test  # prove the check still bites
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

fail() { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
pass() { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }

check_tree() {
    local root="${1:-$ROOT}"

    # 1. The module declares magento, once, in its settings table.
    grep -qE "'PLATFORM' *=> *'magento'" "$root/lib/Settings.php" 2>/dev/null \
        || { echo "lib/Settings.php does not declare PLATFORM => 'magento'"; return 1; }

    # 2. And NOTHING in lib/ hardcodes a platform slug on the wire. Comments are
    #    stripped first: this file's own history names OpenCart at length, and a
    #    guard that fires on its own explanation gets deleted.
    local offenders
    offenders="$(
        for f in "$root"/lib/**/*.php "$root"/lib/*.php; do
            [ -f "$f" ] || continue
            sed -E 's://.*$::' "$f" \
              | sed -E '/\/\*/,/\*\//d' \
              | grep -nE "'platform' *=> *'[a-z0-9]+'" \
              | sed "s|^|$(basename "$f"):|" || true
        done
    )"

    if [ -n "$offenders" ]; then
        echo "a platform slug is hardcoded on the wire in lib/:"
        echo "$offenders" | sed 's/^/    /'
        return 1
    fi

    # 3. Anti-vacuity: the connect body must actually send the key, or a rename
    #    would make this guard pass by having nothing to find.
    grep -q "'platform' =>" "$root/lib/Api/Client.php" 2>/dev/null \
        || { echo "Api/Client.php sends no 'platform' key at all"; return 1; }

    return 0
}

if [ "${1:-}" = "--self-test" ]; then
    tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' EXIT
    mkdir -p "$tmp/lib/Api"
    cp "$ROOT/lib/Settings.php" "$tmp/lib/Settings.php"
    # The exact regression: the vendored hardcode coming back.
    sed "s|'platform' => (string) \$this->settings->get('PLATFORM')|'platform' => 'opencart'|" \
        "$ROOT/lib/Api/Client.php" > "$tmp/lib/Api/Client.php"

    if check_tree "$tmp" >/dev/null 2>&1; then
        fail "self-test: the guard PASSED a client hardcoding another platform's slug"
    fi
    if ! check_tree "$ROOT" >/dev/null 2>&1; then
        fail "self-test: the guard REFUSED the real tree"
    fi
    pass "self-test: refuses a hardcoded foreign slug, accepts the real one"
    exit 0
fi

if out="$(check_tree "$ROOT")"; then
    pass "this module declares its own platform slug, and lib/ hardcodes none"
else
    fail "$out"
fi
