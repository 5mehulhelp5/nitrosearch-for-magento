#!/usr/bin/env bash
#
# Refuse to ship merchant-readable copy that promises something unbuilt.
#
# WHY THIS EXISTS, AND WHY MAGENTO IS THE PLATFORM MOST LIKELY TO TRIP IT.
#
# The WooCommerce plugin's admin screen told merchants they could "see search
# analytics" for a feature that was not built. The listing text was corrected before
# the release went out; the ADMIN SCREEN was not, so the claim shipped and was live to
# everyone who installed it. The first version of that guard read the listing alone —
# which is how it missed the screen. **Anything a merchant can read counts.**
#
# MAGENTO'S SPECIFIC TRAP is `reconcil`. This platform has `bin/magento cron:run` and a
# real job queue, so it is very natural to write "reconciles your catalogue nightly" —
# and plugin-side reconciliation IS NOT BUILT on any connector. The honest words are
# "full walk" or "re-sends the whole catalogue". docs/19 §2.8 calls this out by name
# before a line of copy existed, which is the only reason it is caught here rather than
# by a merchant.
#
# THE DISCLAIMER ARM IS DELIBERATE. A line may carry a flagged word if it also carries
# a roadmap marker — "not yet", "planned", "does not", "no ". Saying plainly that
# something is absent is the opposite of the failure, and a guard that forbade the word
# entirely would push honest limitations off the page.
#
#   bin/check-unbuilt-claims.sh              # check the working tree
#   bin/check-unbuilt-claims.sh --self-test  # prove the check still bites
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

fail() { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
pass() { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }

check_tree() {
    local root="${1:-$ROOT}"

    python3 - "$root" <<'PY' || return 1
import os, re, sys

root = sys.argv[1]

# EVERY SURFACE A MERCHANT CAN READ — derived by walking, not by naming files, so a
# new template or command description is covered on the day it is added.
SURFACES = []
for base, pats in (
    ('.',                  ('README.md',)),
    ('etc/adminhtml',      ('.xml',)),
    ('view/adminhtml',     ('.phtml',)),
    ('Console/Command',    ('.php',)),
    ('i18n',               ('.csv',)),
):
    d = os.path.join(root, base)
    if not os.path.isdir(d):
        continue
    for dirpath, _, files in os.walk(d):
        for f in files:
            if any(f.endswith(p) or f == p for p in pats):
                SURFACES.append(os.path.join(dirpath, f))

if not SURFACES:
    print('no merchant-readable surfaces found — the guard is broken, not the copy')
    sys.exit(1)

CLAIMS = re.compile(r'nightly|reconcil|health.?check|merchandis|personalis|personaliz|a/b test', re.I)
# Saying a thing is absent is the opposite of claiming it.
DISCLAIM = re.compile(r'not yet|planned|do(es)? not|don\'t|never|no |without|absent|unbuilt|roadmap', re.I)

bad = []
for path in SURFACES:
    try:
        lines = open(path, encoding='utf-8', errors='replace').read().splitlines()
    except Exception:
        continue
    for n, line in enumerate(lines, 1):
        if CLAIMS.search(line) and not DISCLAIM.search(line):
            bad.append(f'{os.path.relpath(path, root)}:{n}: {line.strip()[:110]}')

if bad:
    print('merchant-readable copy claims something unbuilt:')
    for b in bad:
        print('   ' + b)
    sys.exit(1)
PY

    return 0
}

if [ "${1:-}" = "--self-test" ]; then
    tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' EXIT
    mkdir -p "$tmp/etc/adminhtml"
    # The exact sentence docs/19 §2.8 predicted someone would write.
    cp "$ROOT/README.md" "$tmp/README.md"
    printf 'NitroSearch reconciles your catalogue nightly so nothing drifts.\n' >> "$tmp/README.md"
    cp "$ROOT/etc/adminhtml/system.xml" "$tmp/etc/adminhtml/system.xml"

    if check_tree "$tmp" >/dev/null 2>&1; then
        fail "self-test: the guard PASSED copy claiming nightly reconciliation"
    fi
    if ! check_tree "$ROOT" >/dev/null 2>&1; then
        fail "self-test: the guard REFUSED the real tree — see the output above"
    fi
    pass "self-test: refuses an unbuilt claim, accepts the real copy"
    exit 0
fi

if out="$(check_tree "$ROOT")"; then
    pass "no merchant-readable surface promises something unbuilt"
else
    fail "$out"
fi
