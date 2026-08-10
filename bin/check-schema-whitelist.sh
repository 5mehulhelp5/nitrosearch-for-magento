#!/usr/bin/env bash
#
# Refuse to ship a declarative schema whose whitelist has drifted from it.
#
# WHY THIS EXISTS. Magento's declarative schema will not DROP anything it does not
# find in `etc/db_schema_whitelist.json`. The file is generated, not written:
#
#     bin/magento setup:db-declaration:generate-whitelist --module-name=NitroSearch_Search
#
# and it must be regenerated and committed whenever `db_schema.xml` changes.
#
# THE FAILURE IS PARTIAL, WHICH IS WHY IT NEEDS A GUARD. A missing whitelist entry
# does not error. `setup:upgrade` applies the parts it recognises and silently skips
# the rest, so a merchant ends up with a table that is half the new shape and half the
# old — and the module then fails at runtime on a column that its own schema says
# exists. Documented as one of the most common third-party module bugs, and invisible
# from every angle except this comparison.
#
# THE CHECK IS STRUCTURAL, NOT A DIFF. It asserts every table and every column in
# `db_schema.xml` has a whitelist entry. It deliberately does NOT require the reverse:
# a whitelist may legitimately retain entries for things a later version removed, which
# is the whole point of the file — that history is how Magento knows it is allowed to
# drop them.
#
#   bin/check-schema-whitelist.sh              # check the working tree
#   bin/check-schema-whitelist.sh --self-test  # prove the check still bites
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

fail() { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

# ⚠ DECLARE THE INTERPRETER, DO NOT ASSUME IT. The checks below parse JSON with
# python3. When it is merely ABSENT the parse fails, and an undeclared dependency
# then reads as a defect in the module — a sibling check spent a build reporting
# "malformed XML in etc/db_schema.xml" about a file that was perfectly well formed,
# because nothing had parsed it at all. GitHub's runners ship python3, so CI cannot
# see this; a bare php container does. Fail on the real cause instead.
command -v python3 >/dev/null 2>&1 || fail "python3 is required by this check (it parses JSON) and is not on PATH — this is a missing tool, not a problem with the module"

pass() { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }

check_tree() {
    local root="${1:-$ROOT}"

    python3 - "$root" <<'PY' || return 1
import json, os, sys
import xml.etree.ElementTree as ET

root = sys.argv[1]
schema = os.path.join(root, 'etc', 'db_schema.xml')
wl     = os.path.join(root, 'etc', 'db_schema_whitelist.json')

if not os.path.isfile(schema):
    print('missing etc/db_schema.xml'); sys.exit(1)
if not os.path.isfile(wl):
    print('missing etc/db_schema_whitelist.json — regenerate it with '
          'bin/magento setup:db-declaration:generate-whitelist --module-name=NitroSearch_Search')
    sys.exit(1)

try:
    allowed = json.load(open(wl))
except Exception as e:
    print(f'db_schema_whitelist.json is not valid JSON: {e}'); sys.exit(1)

tree = ET.parse(schema)
tables = tree.getroot().findall('table')
if not tables:
    # ANTI-VACUITY. A schema this guard cannot parse would otherwise pass by
    # having nothing to compare.
    print('no <table> elements parsed out of db_schema.xml — the guard is broken, not the schema')
    sys.exit(1)

for t in tables:
    name = t.get('name')
    if name not in allowed:
        print(f'table {name!r} is in db_schema.xml but not in the whitelist — '
              'regenerate it, or setup:upgrade will silently skip part of the change')
        sys.exit(1)

    cols = allowed[name].get('column', {})
    for c in t.findall('column'):
        cn = c.get('name')
        if cn not in cols:
            print(f'column {name}.{cn} is in db_schema.xml but not in the whitelist')
            sys.exit(1)
PY

    return 0
}

if [ "${1:-}" = "--self-test" ]; then
    tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' EXIT
    mkdir -p "$tmp/etc"
    cp "$ROOT/etc/db_schema.xml" "$tmp/etc/db_schema.xml"
    # A whitelist missing one column — exactly what forgetting to regenerate looks
    # like after adding a field.
    python3 - "$ROOT" "$tmp" <<'PY'
import json, sys, os
src, dst = sys.argv[1], sys.argv[2]
d = json.load(open(os.path.join(src, 'etc', 'db_schema_whitelist.json')))
t = next(iter(d))
col = next(iter(d[t]['column']))
del d[t]['column'][col]
json.dump(d, open(os.path.join(dst, 'etc', 'db_schema_whitelist.json'), 'w'), indent=4)
PY

    if check_tree "$tmp" >/dev/null 2>&1; then
        fail "self-test: the guard PASSED a whitelist missing a column"
    fi
    if ! check_tree "$ROOT" >/dev/null 2>&1; then
        fail "self-test: the guard REFUSED the real tree"
    fi
    pass "self-test: refuses a drifted whitelist, accepts the real one"
    exit 0
fi

if out="$(check_tree "$ROOT")"; then
    pass "the schema whitelist covers every table and column in db_schema.xml"
else
    fail "$out"
fi
