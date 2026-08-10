#!/usr/bin/env bash
#
# Refuse to ship an attribution path that cannot record an order, records them all as
# the same one, throws orders away, or reports the wrong amount of money.
#
# FOUR SCARS. Every one of them was found by placing a real order; not one of them was
# visible in a review, and not one produced an error message anywhere.
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
# 3. EVERY 4xx DELETED THE REPORT (2026-08-10). The client answered a bare boolean and
#    called every 4xx "handled", so the queue deleted the row. Three of those statuses
#    are conditions a shop comes back from on its own — 429 throttled (the endpoint
#    takes sixty reports a minute per store), 409 not verified YET, 423 account
#    suspended — and each one destroyed one order's attributed revenue permanently,
#    with nothing left anywhere to say a number had gone missing. The 429 is the
#    expensive one: it lands hardest during a flash sale, so the busiest hour of the
#    year reported the least revenue — the hour a merchant uses to judge whether search
#    is worth paying for.
#
# 4. THE MONEY WAS SCALED BY A HARDCODED HUNDRED (2026-08-10). `round($amount * 100)`
#    is right for dollars and wrong for about fifty currencies: a JPY store reported a
#    HUNDRED TIMES its revenue and a KWD store a TENTH of it, on every order, since the
#    module shipped. The payload is well formed and the service accepts it, so the only
#    symptom is a plausible number that is wrong by two orders of magnitude. THIS GUARD
#    HAD NO MONEY CHECK AT ALL until the defect was found — it checked that an order
#    was recorded, never that the amount was right, and a guard is only as wide as the
#    question it asks.
#
# SO THIS CHECKS THE PROPERTIES EACH FAILURE NEEDED, independently, because any one of
# them can be right while another is wrong and the result is the same silent, plausible
# number.
#
# ⚠ WHAT THIS GUARD CANNOT SEE, and no reading of it should suggest otherwise:
#
#   • WHETHER THE SUM IS RIGHT. It checks that the currency's exponent is consulted and
#     that nobody multiplied by a hundred. A wrong total built out of the right table
#     passes here; only an order in a zero-decimal currency, placed for real, can catch
#     that.
#   • WHETHER THE OBSERVER'S EVENT NAME IS SPELLED CORRECTLY, or whether the marker
#     survives from add-to-cart to order placement. Both are sandbox steps.
#   • WHETHER A BRANCH IS REACHABLE. The checks below are text matches on shapes, so
#     code that can never run satisfies them.
#   • WHETHER THE SERVICE STILL CLAMPS AT EIGHT DAYS. That number lives on the other
#     side of the wire; this only enforces that the module's own TTL stays under
#     whatever the module says the clamp is.
#
#   bin/check-order-attribution.sh              # check the working tree
#   bin/check-order-attribution.sh <path>       # check some other checkout
#   bin/check-order-attribution.sh --self-test  # prove the check still bites
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

fail() { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
pass() { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }

MODEL='Model/OrderAttribution.php'   # capture, queue, and the flush that sends
CLIENT='lib/Api/Client.php'          # the wire, and the classification of its answers

# XML COMMENTS ARE STRIPPED BEFORE ANYTHING IS SCANNED, and that is not tidiness.
# Both of these files explain the bug at length, by name — `etc/frontend/events.xml`
# says "sales_order_place_after used to sit in this file" — so a guard reading raw
# text finds every forbidden string in the prose describing why it is forbidden, and
# condemns the corrected tree. [D-056]'s guard learned the same lesson.
strip_comments() { awk '{ gsub(/<!--.*-->/, ""); if (/<!--/) { sub(/<!--.*/, ""); c=1 } else if (c) { if (/-->/) { sub(/.*-->/, ""); c=0 } else next } print }' "$1"; }

# The same rule for PHP, and it matters MORE here. This repo writes long docblocks that
# name the defect a piece of code exists for: the money helper's says "THIS REPLACES A
# HARDCODED × 100" and quotes the line it replaced, and the client's spells out the
# `>= 400 && < 500` shape it no longer has. A guard reading raw text would find every
# forbidden string inside the prose explaining why it is forbidden — passing a tree that
# only TALKS about the rule and condemning one that follows it.
strip_php_comments() { sed -e 's://.*::' -e 's:#.*::' -e 's:^[[:space:]]*\*.*::' -e 's:^[[:space:]]*/\*.*::' "$1" 2>/dev/null; }

# One method's body, comments already gone. Found by NAME, and callers that care about
# behaviour rather than naming (the send path) locate the name first by what it calls.
method_block() {
    strip_php_comments "$1" | awk -v name="$2" '
        !inside && $0 ~ "function[[:space:]]+" name "[[:space:]]*\\(" { inside = 1 }
        inside {
            print
            opened += gsub(/\{/, "{")
            closed += gsub(/\}/, "}")
            if (opened > 0 && opened == closed) { exit }
        }'
}

# The method that actually sends, identified by what it does rather than by what it is
# called: renaming it must not quietly disable the checks that read it.
send_method() {
    strip_php_comments "$1" | awk '
        /function[[:space:]]+[A-Za-z_]+[[:space:]]*\(/ {
            match($0, /function[[:space:]]+[A-Za-z_]+/)
            m = substr($0, RSTART, RLENGTH)
            sub(/function[[:space:]]+/, "", m)
        }
        /reportOrder[[:space:]]*\(/ { print m; exit }'
}

# An integer constant's value, from live code only.
const_value() {
    strip_php_comments "$1" | sed -n "s/.*$2[[:space:]]*=[[:space:]]*\([0-9][0-9]*\).*/\1/p" | head -1
}

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

    [ -f "$root/$MODEL" ] || { echo "$MODEL is missing — this module cannot attribute an order at all"; return 1; }
    [ -f "$root/$CLIENT" ] || { echo "$CLIENT is missing — nothing can send a report"; return 1; }

    # 4. And the belt to that brace: the queue refuses an order with no id, so a
    #    future move back to a pre-save event loses an attribution rather than
    #    silently collapsing every order into one. Matched on the SHAPE of the
    #    comparison rather than one literal spelling, so renaming a variable on a
    #    correct tree does not fail it.
    strip_php_comments "$root/$MODEL" \
        | grep -qE '\$[A-Za-z_]*[Oo]rder[A-Za-z_]*[[:space:]]*(<=[[:space:]]*0|<[[:space:]]*1)' \
        || { echo "$MODEL never refuses an order with no entity id — every report would carry the same order_ref and the service would fold them into one"; return 1; }

    # 5. THE MONEY. Both halves, because either alone is satisfiable by a tree that
    #    still gets it wrong: nothing may multiply by a hundred, AND the currency's
    #    exponent must actually be consulted. The table is generated from the same
    #    source the service uses and is already vendored in this module for catalogue
    #    prices — this line was the one written from memory.
    if strip_php_comments "$root/$MODEL" | grep -qE '(\*[[:space:]]*100([^0-9]|$)|(^|[^0-9])100[[:space:]]*\*)'; then
        echo "$MODEL multiplies by 100 — right for dollars, wrong for JPY (100x) and KWD (a tenth); the vendored exponent table exists for exactly this"
        return 1
    fi

    strip_php_comments "$root/$MODEL" | grep -q 'CurrencyExponents::for(' \
        || { echo "$MODEL never consults CurrencyExponents — minor units cannot be derived without the currency's exponent"; return 1; }

    # 6. THE CLASSIFICATION OF THE SERVICE'S ANSWER. The blanket "every 4xx is
    #    handled" shape is refused by name, and each status a shop comes back from
    #    must be listed individually — a list that quietly loses one entry is the
    #    original defect in miniature.
    local report_block
    report_block="$(method_block "$root/$CLIENT" 'reportOrder')"

    [ -n "$report_block" ] || { echo "$CLIENT has no reportOrder() — nothing sends an order report"; return 1; }

    if printf '%s\n' "$report_block" | grep -qE '400[^0-9].*500|500[^0-9].*400'; then
        echo "$CLIENT treats a whole 4xx range as one answer — 429 (throttled), 409 (not verified yet) and 423 (suspended) are states a shop comes back from, and deleting those reports loses the merchant real revenue"
        return 1
    fi

    # The list itself is a class constant rather than something spelled out inside the
    # method, so it is read where it is declared and the method is required to consult
    # it — a list nothing reads is as good as no list.
    local retry_list
    retry_list="$(strip_php_comments "$root/$CLIENT" | grep -E '\$orderRetryCodes[[:space:]]*=' | head -1)"

    [ -n "$retry_list" ] || { echo "$CLIENT declares no list of retryable order-report statuses"; return 1; }

    printf '%s\n' "$report_block" | grep -qF 'orderRetryCodes' \
        || { echo "$CLIENT declares retryable statuses but reportOrder() never consults them"; return 1; }

    local code
    for code in 401 408 409 423 425 429; do
        if ! printf '%s\n' "$retry_list" | grep -qE "(^|[^0-9])$code([^0-9]|$)"; then
            echo "$CLIENT does not treat HTTP $code as retryable — that answer means 'ask again', and dropping the report there costs one order's revenue permanently"
            return 1
        fi
    done

    printf '%s\n' "$report_block" | grep -qE '(>=[[:space:]]*500|>[[:space:]]*499)' \
        || { echo "$CLIENT does not retry a 5xx order report"; return 1; }

    printf '%s\n' "$report_block" | grep -qE '\$status[[:space:]]*===[[:space:]]*0' \
        || { echo "$CLIENT does not retry a transport failure (which arrives as status 0, not as a 5xx)"; return 1; }

    # 7. NO NETWORK ON THE CHECKOUT PATH, ENFORCED BY CONSTRUCTION RATHER THAN BY
    #    INTENTION. `markFromSearch` runs inside add-to-cart and `queueReport` inside
    #    order placement; both are the shopper's own request. If either can reach a
    #    client or a socket, then a slow or unreachable service becomes a slow or
    #    broken checkout, and no try/catch fixes a timeout because the shopper has
    #    already waited. Sending is the flush's job, and the flush runs on the
    #    heartbeat.
    #
    #    ⚠ IT IS CHECKED BEFORE THE SEND PATH IS EVEN LOCATED, because the send path is
    #    identified as "the method that calls reportOrder" — so a checkout method given
    #    a way to send would BECOME the send path as far as the checks below are
    #    concerned, and be reported as some subtler fault of the flush instead of as
    #    the one thing here that can cost a merchant a sale.
    local checkout_method token
    for checkout_method in markFromSearch queueReport; do
        local block
        block="$(method_block "$root/$MODEL" "$checkout_method")"
        [ -n "$block" ] || { echo "$MODEL has no ${checkout_method}() — the checkout path this guard is meant to police is not where it is looked for"; return 1; }

        for token in 'Client' 'curl_' 'file_get_contents' 'fsockopen' 'stream_socket' 'reportOrder('; do
            if printf '%s\n' "$block" | grep -qF "$token"; then
                echo "$MODEL::${checkout_method}() references '$token' — that is network on a shopper's own checkout request, which no exception handler can make safe"
                return 1
            fi
        done
    done

    # 8. AND THE CALLER HAS TO READ THAT ANSWER. A tri-state consumed as a boolean is
    #    the same bug one file further along: `if (!$outcome)` on a non-empty array is
    #    always false, so every report would look accepted and every row would be
    #    deleted — including the ones that were never sent.
    local flush_method flush_block
    flush_method="$(send_method "$root/$MODEL")"

    [ -n "$flush_method" ] || { echo "$MODEL never calls reportOrder() — nothing in this module sends a queued report"; return 1; }

    flush_block="$(method_block "$root/$MODEL" "$flush_method")"

    printf '%s\n' "$flush_block" | grep -qF "'done'" \
        || { echo "$MODEL::${flush_method}() does not read 'done' from the client's answer — a retryable failure would be deleted as if it had been accepted"; return 1; }

    # 9. `occurred_at` IS READ FROM THE ROW AND NEVER RE-DERIVED AT SEND TIME. It is
    #    half of the key the service dedupes on, so a value recomputed per attempt
    #    makes a retry a SECOND conversion row for one order — the merchant's revenue
    #    counted twice, silently, on exactly the orders that had to be retried. That is
    #    the opposite failure to the one retrying exists to fix, and the worse of the
    #    two. (A clock elsewhere in the file is fine: expiring stale rows needs one.)
    if printf '%s\n' "$flush_block" | grep -qE '\b(gmdate|date|time|strtotime|mktime)[[:space:]]*\('; then
        echo "$MODEL::${flush_method}() derives a timestamp at send time — occurred_at must be a pure function of the stored row, or every retry double-counts the merchant's revenue"
        return 1
    fi

    printf '%s\n' "$flush_block" | grep -qF 'occurred_at' \
        || { echo "$MODEL::${flush_method}() never reads occurred_at from the row"; return 1; }

    # 10. THE QUEUE GIVES UP BEFORE THE SERVICE STARTS REWRITING TIMESTAMPS. Past its
    #    acceptance window the service clamps `occurred_at` to the edge of that window,
    #    and the edge MOVES with the clock — so two attempts on two days stop deduping
    #    against each other and land as two conversion rows. Retries now span days, so
    #    this relationship is load-bearing rather than theoretical. Read as numbers,
    #    both required to exist, so a deleted constant fails rather than passing on an
    #    empty comparison.
    local ttl clamp
    ttl="$(const_value "$root/$MODEL" 'REPORT_TTL_DAYS')"
    clamp="$(const_value "$root/$MODEL" 'SERVICE_CLAMP_DAYS')"

    if [ -z "$ttl" ] || [ -z "$clamp" ]; then
        echo "$MODEL does not declare both REPORT_TTL_DAYS and SERVICE_CLAMP_DAYS — the queue's give-up point has nothing to be checked against"
        return 1
    fi

    if [ "$ttl" -ge "$clamp" ]; then
        echo "$MODEL keeps reports for $ttl days but the service only accepts a timestamp $clamp days old — anything sent past that is clamped to a MOVING value, so a retry stops deduping and double-counts the merchant's revenue"
        return 1
    fi

    return 0
}

if [ "${1:-}" = "--self-test" ]; then
    tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' EXIT

    # A complete, correct copy of everything check_tree reads. Every case below starts
    # from this and breaks exactly one thing — a fixture assembled per case drifts from
    # the real tree, and a guard proved only against its own fixtures is what let the
    # money bug through.
    build() {
        rm -rf "$tmp/bad"
        mkdir -p "$tmp/bad/etc/frontend" "$tmp/bad/Model" "$tmp/bad/lib/Api"
        cp "$ROOT/etc/events.xml" "$tmp/bad/etc/events.xml"
        cp "$ROOT/etc/frontend/events.xml" "$tmp/bad/etc/frontend/events.xml"
        cp "$ROOT/$MODEL" "$tmp/bad/$MODEL"
        cp "$ROOT/$CLIENT" "$tmp/bad/$CLIENT"
    }

    fires() {
        local label="$1" expect="$2" out
        if out="$(check_tree "$tmp/bad" 2>&1)"; then
            fail "self-test: the guard PASSED — $label"
        fi
        case "$out" in
            *"$expect"*) printf '  \033[1;32mok\033[0m  fires on: %s\n' "$label" ;;
            *) fail "self-test: the guard fired on the wrong thing for '$label': $out" ;;
        esac
    }

    # (a) The original shape: order placement in the frontend file, no global one.
    build
    rm -f "$tmp/bad/etc/events.xml"
    sed 's#</config>#    <event name="sales_order_place_after"><observer name="x" instance="NitroSearch\\Search\\Observer\\ReportPlacedOrder"/></event>\n</config>#' \
        "$ROOT/etc/frontend/events.xml" > "$tmp/bad/etc/frontend/events.xml"
    fires "order placement registered in the frontend area only" "no etc/events.xml"

    # (b) Global scope, but back on the pre-save event — the order_id 0 shape. Added
    #     ALONGSIDE the correct registration rather than replacing it, because a tree
    #     that observes both is the one a check on the right event would still pass.
    build
    sed 's#</config>#    <event name="sales_order_place_after"><observer name="x" instance="NitroSearch\\Search\\Observer\\ReportPlacedOrder"/></event>\n</config>#' \
        "$ROOT/etc/events.xml" > "$tmp/bad/etc/events.xml"
    fires "an observer on the pre-save event (every report carries order_id 0)" "it runs BEFORE the order row exists"

    # (c) Everything registered right, but the id bail removed.
    build
    grep -v 'orderId <= 0' "$ROOT/$MODEL" > "$tmp/bad/$MODEL"
    fires "a queue that accepts an order with no id" "refuses an order with no entity id"

    # (d) THE MONEY BUG, WRITTEN THE WAY IT WAS ACTUALLY WRITTEN AND SHIPPED.
    build
    sed 's|^        \$exponent = CurrencyExponents::for(\$currency);|        return (int) round(((float) $amount) * 100);|' \
        "$ROOT/$MODEL" > "$tmp/bad/$MODEL"
    fires "value converted with a hardcoded x 100 (a JPY store reports 100x)" "multiplies by 100"

    # (e) The exponent table dropped altogether — a fixed exponent is the same bug
    #     wearing the right shape.
    build
    sed 's|CurrencyExponents::for(\$currency)|2|' "$ROOT/$MODEL" > "$tmp/bad/$MODEL"
    fires "the exponent table dropped for a fixed 2" "never consults CurrencyExponents"

    # (f) THE CLASSIFICATION DEFECT, restored exactly as it shipped.
    build
    sed 's|if (\$status === 0 \|\| \$status >= 500 \|\| in_array(\$status, self::\$orderRetryCodes, true)) {|if (!$res["ok"] \&\& $status >= 400 \&\& $status < 500) {|' \
        "$ROOT/$CLIENT" > "$tmp/bad/$CLIENT"
    fires "every 4xx treated as one answer (429/409/423 deleted the order)" "states a shop comes back from"

    # (g) The same defect arriving quietly: one status falls off the retry list.
    build
    sed 's|array(401, 408, 409, 423, 425, 429)|array(401, 408, 409, 423, 425)|' "$ROOT/$CLIENT" > "$tmp/bad/$CLIENT"
    fires "429 quietly dropped from the retry list (the throttle case)" "HTTP 429 as retryable"

    build
    sed 's|array(401, 408, 409, 423, 425, 429)|array(401, 408, 425, 429)|' "$ROOT/$CLIENT" > "$tmp/bad/$CLIENT"
    fires "409 and 423 quietly dropped (unverified, suspended)" "HTTP 409 as retryable"

    # (h) The tri-state consumed as a boolean, one file further along.
    build
    sed "s|if (empty(\$outcome\['done'\])) {|if (!\$outcome) {|" "$ROOT/$MODEL" > "$tmp/bad/$MODEL"
    fires "the client's answer read as a boolean (every row deleted, sent or not)" "does not read 'done'"

    # (i) occurred_at re-derived at send time — the double-count.
    build
    sed "s|\$occurredAt = self::wireTimestamp((string) \$row\['occurred_at'\]);|\$occurredAt = gmdate('c', strtotime((string) \$row['occurred_at']));|" \
        "$ROOT/$MODEL" > "$tmp/bad/$MODEL"
    fires "occurred_at derived at send time (a retry double-counts)" "derives a timestamp at send time"

    # (j) The TTL back past the service's clamp.
    build
    sed 's|private const REPORT_TTL_DAYS = 7;|private const REPORT_TTL_DAYS = 14;|' "$ROOT/$MODEL" > "$tmp/bad/$MODEL"
    fires "reports kept past the window the service accepts a timestamp within" "clamped to a MOVING value"

    build
    grep -v 'private const SERVICE_CLAMP_DAYS' "$ROOT/$MODEL" > "$tmp/bad/$MODEL"
    fires "the clamp constant deleted (nothing left to compare against)" "has nothing to be checked against"

    # (k) The sender reachable from the shopper's own request.
    build
    sed "s|\$this->checkoutSession->unsetData(self::SESSION_KEY);|(new Client(\$this->settings, ''))->reportOrder([]);|" \
        "$ROOT/$MODEL" > "$tmp/bad/$MODEL"
    fires "the queue writer given a way to send (network on checkout)" "network on a shopper's own checkout request"

    if ! out="$(check_tree "$ROOT" 2>&1)"; then
        fail "self-test: the guard REFUSED the real tree — $out"
    fi

    pass "self-test: refuses every shape that produced a silent wrong number, accepts the real one"
    exit 0
fi

if [ -n "${1:-}" ]; then
    # Any other checkout — a build output, a previous release unpacked, the tree as it
    # stood before a change. Checking a release you have already shipped is how the
    # question "would this guard have caught it?" gets an answer instead of a guess.
    ROOT="$(cd "$1" && pwd)"
fi

if out="$(check_tree "$ROOT")"; then
    pass "order attribution is registered where every checkout dispatches it, carries a real order id, reports the right money, and never throws an order away on an answer that means 'ask again'"
else
    fail "$out"
fi
