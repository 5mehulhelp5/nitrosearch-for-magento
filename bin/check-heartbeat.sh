#!/usr/bin/env bash
#
# Refuse to ship a module whose heartbeat can be starved.
#
# WHY THIS EXISTS. Two clocks keep a store alive: a 300s poll and an 86,400s search-key
# refresh. `/v1/status` carries no key, so a store that only polls holds its onboarding
# key until it expires — and then storefront search returns nothing while every admin
# screen still says "connected". The failure is invisible from every surface a merchant
# would think to check, which is why it gets a build gate rather than a code comment.
#
# THREE WAYS TO BREAK IT, AND ALL THREE HAVE HAPPENED ON THIS PROJECT:
#
#  1. Merging the two clocks. One interval cannot serve both jobs — a daily poll is
#     useless for a resync flag, and a five-minute key fetch is abuse.
#  2. Gating the heartbeat on there being sync work. An empty outbox is the steady
#     state of a healthy catalogue, and precisely the store whose key expires.
#  3. Calling the heartbeat from inside the drain. The drain returns early when there
#     is nothing to send, so the call never runs on the store that needs it.
#
# So this asserts the two clocks are distinct, and that the heartbeat is registered as
# its OWN cron job rather than living inside another one.
#
#   bin/check-heartbeat.sh              # check the working tree
#   bin/check-heartbeat.sh --self-test  # prove the check still bites
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

fail() { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
pass() { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }

check_tree() {
    local root="${1:-$ROOT}"
    local crontab="$root/etc/crontab.xml"
    local resync="$root/lib/Sync/ResyncCheck.php"

    [ -f "$crontab" ] || { echo "missing etc/crontab.xml"; return 1; }
    [ -f "$resync" ]  || { echo "missing lib/Sync/ResyncCheck.php"; return 1; }

    # The heartbeat is its OWN job, not a call inside the drain.
    grep -q 'nitrosearch_clocks' "$crontab" \
        || { echo "no nitrosearch_clocks cron job — the heartbeat is not scheduled independently"; return 1; }
    grep -q 'nitrosearch_drain' "$crontab" \
        || { echo "no nitrosearch_drain cron job"; return 1; }

    # TWO clocks, two intervals, two stored stamps. Both constants must exist and
    # they must differ — a single interval means they were merged.
    grep -q 'const INTERVAL' "$resync" \
        || { echo "ResyncCheck has no poll interval"; return 1; }
    grep -q 'const REFRESH_INTERVAL' "$resync" \
        || { echo "ResyncCheck has no key-refresh interval — the two clocks have been merged"; return 1; }

    local poll refresh
    poll="$(sed -n 's/.*const INTERVAL *= *\([0-9]*\).*/\1/p' "$resync" | head -n1)"
    refresh="$(sed -n 's/.*const REFRESH_INTERVAL *= *\([0-9]*\).*/\1/p' "$resync" | head -n1)"

    [ -n "$poll" ] && [ -n "$refresh" ] || { echo "could not read both intervals"; return 1; }
    [ "$poll" != "$refresh" ] || { echo "both clocks have the same interval ($poll) — they have been merged"; return 1; }

    # And the two stamps must be separate keys, or one job starves the other.
    grep -q 'STATUS_CHECKED_AT' "$resync"   || { echo "no STATUS_CHECKED_AT stamp"; return 1; }
    grep -q 'CONFIG_REFRESHED_AT' "$resync" || { echo "no CONFIG_REFRESHED_AT stamp — the clocks share a stamp"; return 1; }

    return 0
}

if [ "${1:-}" = "--self-test" ]; then
    tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' EXIT
    mkdir -p "$tmp/etc" "$tmp/lib/Sync"
    cp "$ROOT/etc/crontab.xml" "$tmp/etc/crontab.xml"
    # The exact regression: both clocks on one interval.
    sed 's/const REFRESH_INTERVAL = 86400/const REFRESH_INTERVAL = 300/' \
        "$ROOT/lib/Sync/ResyncCheck.php" > "$tmp/lib/Sync/ResyncCheck.php"

    if check_tree "$tmp" >/dev/null 2>&1; then
        fail "self-test: the guard PASSED a tree with both clocks on one interval"
    fi
    if ! check_tree "$ROOT" >/dev/null 2>&1; then
        fail "self-test: the guard REFUSED the real tree"
    fi
    pass "self-test: refuses merged clocks, accepts the real one"
    exit 0
fi

if out="$(check_tree "$ROOT")"; then
    pass "two clocks, two intervals, two stamps, and the heartbeat runs on its own"
else
    fail "$out"
fi
