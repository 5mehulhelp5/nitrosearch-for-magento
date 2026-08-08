#!/usr/bin/env bash
#
# Refuse to ship a serializer that quietly sends less than the wire supports.
#
# THE SCAR. This module's first serializer emitted eight keys — id, name, sku,
# visible, price, currency, price_exponent, permalink — and looked like a reasonable
# first cut. It was not. `in_stock` was absent, and the wire treats an absent
# `in_stock` as OUT OF STOCK, so every product on every Magento store indexed
# unbuyable: the results panel rendered "Out of stock" on all of them and the widget
# correctly refused to offer Add to cart on an item it believed nobody could buy.
# Search → cart → order attribution, the thing this connector is sold on, could not
# run once. It was not untested; it was unreachable. Nothing errored, nothing logged,
# and the sync reported success on 2,040 products.
#
# The quieter omissions cost real quality: no `image` (every result a grey box), no
# `description`/`categories`/`brand` (matching on the product NAME alone, and an empty
# facet rail), no `variants` (a configurable looking simple and offering a button that
# could only ever redirect).
#
# SO THE LIST IS DERIVED FROM THE CONTRACT, NOT WRITTEN DOWN HERE. Every public
# product setter on the vendored `ItemBuilder` is a field the wire carries; each one
# must be either USED by the serializer or NAMED IN THE EXCLUSIONS BELOW with a
# reason. A new field added to the shared kit therefore fails this guard until
# somebody decides about it, rather than being absent by default — [D-034]'s
# derive-don't-enumerate rule, which this project has been bitten by five times.
#
# AND THE FAIL-CLOSED FIELDS ARE CHECKED SEPARATELY, because "used somewhere" is not
# the property that matters for them. `visible` and `in_stock` both default to FALSE
# on arrival, so emitting them conditionally means a product that misses the condition
# is silently unreachable rather than merely unadorned. They must sit in the
# unconditional builder chain, which is what this checks.
#
#   bin/check-wire-fields.sh              # check the working tree
#   bin/check-wire-fields.sh --self-test  # prove the check still bites
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

fail() { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
pass() { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }

# Fields the serializer deliberately does not send, each with the reason it is a
# decision rather than an oversight. Anything not here must be used.
#
#   popularity  — Magento Open Source has no cheap popularity signal: bestsellers
#                 need the reports cron aggregating, and a store that has never run
#                 it would get a silent zero indistinguishable from an unpopular
#                 catalogue. Declared as a gap in the README instead of faked.
#   variant     — used, but through the `variants()` helper rather than a literal
#                 `->variant(` in the main chain; matched separately below.
#   attribute   — facets arrive folded in from variant attributes, which is the
#                 wire's documented behaviour; a parent has no separate facet set.
#   excerpt     — content-only. This connector indexes products.
#   publishedAt — content-only, same reason.
EXCLUDED="popularity attribute excerpt publishedAt"

# Builder mechanics rather than wire fields.
MECHANICS="product content delete version toArray"

check_tree() {
    local root="${1:-$ROOT}"
    local builder="$root/vendor-contract/src/ItemBuilder.php"
    local serializer="$root/Model/ProductSerializer.php"

    [ -f "$builder" ] || { echo "no vendored ItemBuilder — nothing to derive the field list from"; return 1; }
    [ -f "$serializer" ] || { echo "no Model/ProductSerializer.php"; return 1; }

    # Every public setter on the contract. THIS is the list, and it is read rather
    # than remembered.
    local setters
    setters="$(sed -n 's/^    public function \([a-zA-Z]*\)(.*/\1/p' "$builder")"

    [ "$(printf '%s\n' "$setters" | grep -c .)" -ge 10 ] \
        || { echo "derived fewer than 10 setters from ItemBuilder — the derivation itself is broken"; return 1; }

    local missing=""
    local name
    for name in $setters; do
        case " $MECHANICS $EXCLUDED " in
            *" $name "*) continue ;;
        esac

        grep -q -- "->${name}(" "$serializer" || missing="$missing $name"
    done

    if [ -n "$missing" ]; then
        echo "the serializer never sends:$missing — every wire field is either used or listed as an excluded decision in this guard"
        return 1
    fi

    # Variants come from a helper, so the literal call lives in the loop.
    grep -q -- '->variant(' "$serializer" \
        || { echo "no ->variant( call — configurables would arrive looking like simple products"; return 1; }

    # THE FAIL-CLOSED FIELDS, in the unconditional chain. Extract the UPSERT
    # statement — `$builder = ItemBuilder::product(…)` through its first `;` — and
    # require both fields inside it. Anchored on the assignment rather than on
    # `ItemBuilder::product(` alone, because the delete branch a few lines above is
    # also a builder chain and matching it first would make this vacuous.
    local chain
    chain="$(awk '/\$builder = ItemBuilder::product\(/{grab=1} grab{print; if (/;[[:space:]]*$/) exit}' "$serializer")"

    printf '%s' "$chain" | grep -q 'ItemBuilder::product(' \
        || { echo "could not find the upsert builder chain in the serializer — this guard cannot see what it claims to check"; return 1; }

    local field
    for field in visible inStock; do
        printf '%s' "$chain" | grep -q -- "->${field}(" \
            || { echo "${field} is not in the unconditional builder chain — the wire reads it as FALSE when absent, so a skipped condition makes a product unreachable rather than merely plainer"; return 1; }
    done

    return 0
}

if [ "${1:-}" = "--self-test" ]; then
    tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' EXIT
    mkdir -p "$tmp/vendor-contract/src" "$tmp/Model"
    cp "$ROOT/vendor-contract/src/ItemBuilder.php" "$tmp/vendor-contract/src/ItemBuilder.php"

    # The exact tree that shipped: in_stock moved out of the chain into a condition
    # that can be false. Everything else identical, which is why it read as fine.
    sed 's/->inStock(\$inStock\[\$id\] ?? true)/->visible(true)/' \
        "$ROOT/Model/ProductSerializer.php" > "$tmp/Model/ProductSerializer.php"

    if check_tree "$tmp" >/dev/null 2>&1; then
        fail "self-test: the guard PASSED a serializer that never sends in_stock"
    fi

    # And an anti-vacuity check in the other direction: a tree with the image field
    # dropped entirely must also fail, so the guard is not merely watching one line.
    rm -rf "$tmp/Model"; mkdir -p "$tmp/Model"
    grep -v -- '->image(' "$ROOT/Model/ProductSerializer.php" > "$tmp/Model/ProductSerializer.php"

    if check_tree "$tmp" >/dev/null 2>&1; then
        fail "self-test: the guard PASSED a serializer that never sends image"
    fi

    if ! check_tree "$ROOT" >/dev/null 2>&1; then
        fail "self-test: the guard REFUSED the real tree"
    fi

    pass "self-test: refuses a serializer missing in_stock or image, accepts the real one"
    exit 0
fi

if out="$(check_tree "$ROOT")"; then
    pass "the serializer sends every field the wire carries, and the fail-closed ones unconditionally"
else
    fail "$out"
fi
