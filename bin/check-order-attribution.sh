#!/usr/bin/env bash
#
# Refuse to ship an attribution path that cannot record an order, or records them
# all as the same one.
#
# TWO SCARS, BOTH FOUND BY PLACING A REAL ORDER AND NEITHER VISIBLE IN A REVIEW.
#
# 1. THE OBSERVER WAS IN THE WRONG AREA. Order placement was registered in
#    `etc/frontend/events.xml`, next to the add-to-cart capture observer, reading like
#    its natural pair: both concern a shopper's own session, and an admin-created
#    order has no search marker. **Luma's one-page checkout does not place orders in
#    the frontend area.** It submits to
#    `POST /rest/<store>/V1/guest-carts/{id}/payment-information`, which Magento
#    executes in `webapi_rest`, where a frontend-scoped observer is not registered and
#    never runs. Three orders were placed on the sandbox before anybody looked at the
#    table; it had zero rows, and the proof was the marker still sitting in the
#    session afterwards, since queueing is what clears it.
#
# 2. THE ORDER ID WAS ALWAYS ZERO. `sales_order_place_after` is dispatched from
#    `Order::place()`, which `OrderManagement::place()` calls immediately BEFORE
#    saving — so `getEntityId()` is null and `(int) null` is 0. Locally `order_id` is
#    UNIQUE and the write is `insertOnDuplicate`, so the second attributed order
#    overwrites the first. On the wire it is worse and quieter:
#    `order_ref = sha256(install_id | order | order_id)` becomes a CONSTANT, so the
#    service's own dedupe folds every order a store ever attributed into one. A
#    merchant sees a single attributed order forever and nothing errors.
#
# SO THIS CHECKS THE THREE PROPERTIES THAT FAILURE NEEDED, each independently, because
# any one of them can be right while another is wrong and the result is the same
# silent zero.
#
#   bin/check-order-attribution.sh              # check the working tree
#   bin/check-order-attribution.sh --self-test  # prove the check still bites
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

fail() { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
pass() { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }

# XML COMMENTS ARE STRIPPED BEFORE ANYTHING IS SCANNED, and that is not tidiness.
# Both of these files explain the bug at length, by name — `etc/frontend/events.xml`
# says "sales_order_place_after used to sit in this file" — so a guard reading raw
# text finds every forbidden string in the prose describing why it is forbidden, and
# condemns the corrected tree. [D-056]'s guard learned the same lesson.
strip_comments() { awk '{ gsub(/<!--.*-->/, ""); if (/<!--/) { sub(/<!--.*/, ""); c=1 } else if (c) { if (/-->/) { sub(/.*-->/, ""); c=0 } else next } print }' "$1"; }

check_tree() {
    local root="${1:-$ROOT}"

    # 1. Registered at GLOBAL scope. Not "in some events.xml" — in the one Magento
    #    loads for every area, because the areas a shopper's order can be placed in
    #    are not a list this module gets to enumerate correctly.
    [ -f "$root/etc/events.xml" ] \
        || { echo "no etc/events.xml — an order observer registered only in an area file misses whichever area the merchant's checkout actually uses"; return 1; }

    strip_comments "$root/etc/events.xml" | grep -q 'sales_order_save_after' \
        || { echo "etc/events.xml does not observe sales_order_save_after"; return 1; }

    strip_comments "$root/etc/events.xml" | grep -q 'ReportPlacedOrder' \
        || { echo "etc/events.xml observes the event but not with the report observer"; return 1; }

    # 2. And NOT area-scoped anywhere. A duplicate registration in an area file would
    #    fire the observer twice in that area; a lone one is the original bug.
    local f
    for f in "$root/etc/"*/events.xml; do
        [ -f "$f" ] || continue
        if strip_comments "$f" | grep -q 'sales_order_'; then
            echo "an area-scoped events.xml registers a sales_order_ event ($f) — order placement belongs at global scope"
            return 1
        fi
    done

    # 3. Never the pre-save event, whatever the file. This is the one that hands out
    #    an entity id of 0.
    for f in "$root/etc/events.xml" "$root/etc/"*/events.xml; do
        [ -f "$f" ] || continue
        if strip_comments "$f" | grep -q 'sales_order_place_after'; then
            echo "sales_order_place_after is observed in $f — it runs BEFORE the order row exists, so every report would carry order_id 0"
            return 1
        fi
    done

    # 4. And the belt to that brace: the queue refuses an order with no id, so a
    #    future move back to a pre-save event loses an attribution rather than
    #    silently collapsing every order into one.
    grep -q 'orderId <= 0' "$root/Model/OrderAttribution.php" 2>/dev/null \
        || { echo "OrderAttribution does not refuse an order with no entity id"; return 1; }

    return 0
}

if [ "${1:-}" = "--self-test" ]; then
    tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' EXIT

    build() {
        rm -rf "$tmp"; mkdir -p "$tmp/etc/frontend" "$tmp/Model"
        cp "$ROOT/etc/frontend/events.xml" "$tmp/etc/frontend/events.xml"
        cp "$ROOT/Model/OrderAttribution.php" "$tmp/Model/OrderAttribution.php"
    }

    # (a) The original shape: order placement in the frontend file, no global one.
    build
    sed 's#</config>#    <event name="sales_order_place_after"><observer name="x" instance="NitroSearch\\Search\\Observer\\ReportPlacedOrder"/></event>\n</config>#' \
        "$ROOT/etc/frontend/events.xml" > "$tmp/etc/frontend/events.xml"

    if check_tree "$tmp" >/dev/null 2>&1; then
        fail "self-test: the guard PASSED a frontend-only order observer"
    fi

    # (b) Global scope, but back on the pre-save event — the order_id 0 shape.
    build
    cp "$ROOT/etc/events.xml" "$tmp/etc/events.xml"
    sed -i.bak 's/sales_order_save_after/sales_order_place_after/' "$tmp/etc/events.xml"

    if check_tree "$tmp" >/dev/null 2>&1; then
        fail "self-test: the guard PASSED an observer on the pre-save event"
    fi

    # (c) Everything registered right, but the id bail removed.
    build
    cp "$ROOT/etc/events.xml" "$tmp/etc/events.xml"
    grep -v 'orderId <= 0' "$ROOT/Model/OrderAttribution.php" > "$tmp/Model/OrderAttribution.php"

    if check_tree "$tmp" >/dev/null 2>&1; then
        fail "self-test: the guard PASSED a queue that accepts an order with no id"
    fi

    if ! check_tree "$ROOT" >/dev/null 2>&1; then
        fail "self-test: the guard REFUSED the real tree"
    fi

    pass "self-test: refuses all three shapes that produced a silent zero, accepts the real one"
    exit 0
fi

if out="$(check_tree "$ROOT")"; then
    pass "order attribution is registered where every checkout dispatches it, and carries a real order id"
else
    fail "$out"
fi
