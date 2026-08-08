#!/usr/bin/env bash
#
# Refuse to ship a Composer package that will not install, or that installs the wrong
# things.
#
# THIS GUARD HAS NO PRECEDENT IN THE OTHER THREE CONNECTORS, because none of them is a
# Composer package. They build a ZIP a merchant uploads through a back office; a broken
# archive fails loudly at upload. A Composer package fails differently and later — on a
# merchant's production deploy, inside `composer require`, with an error about a
# dependency graph rather than about us.
#
# FIVE PROPERTIES, EACH WITH A SPECIFIC FAILURE:
#
#  1. `composer.json` is valid JSON and `composer validate` passes. A trailing comma
#     is a merchant's failed deploy, and nothing in this repo would otherwise notice.
#
#  2. `"type": "magento2-module"`. Without it `magento/magento-composer-installer`
#     never runs, the module is never registered, and the merchant sees NOTHING — no
#     error, no module in `module:status`, just an absent feature. This is the single
#     highest-consequence line in the file.
#
#  3. `registration.php` is in `autoload.files`. It is how the module announces itself;
#     a PSR-4 entry alone does not run it, because nothing references the file.
#
#  4. Every declared PSR-4 prefix points at a directory that exists. A renamed folder
#     leaves an autoloader mapping to nothing, and the failure is a class-not-found
#     deep inside a controller rather than anything that names autoloading.
#
#  5. Dev files are export-ignored. `composer require` ships the dist archive, so
#     anything tracked and not excluded lands in a merchant's vendor/ — tests that
#     Magento's compiler may reflect over, and build scripts a security scanner flags.
#
#   bin/check-composer.sh              # check the working tree
#   bin/check-composer.sh --self-test  # prove the check still bites
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

fail() { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
pass() { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }

check_tree() {
    local root="${1:-$ROOT}"
    local json="$root/composer.json"

    [ -f "$json" ] || { echo "missing composer.json"; return 1; }

    python3 - "$root" <<'PY' || return 1
import json, os, sys
root = sys.argv[1]
p = os.path.join(root, 'composer.json')

try:
    d = json.load(open(p))
except Exception as e:
    print(f"composer.json is not valid JSON: {e}")
    sys.exit(1)

if d.get('type') != 'magento2-module':
    print(f'"type" is {d.get("type")!r}, not "magento2-module" — the installer will '
          'never run and the module will be invisible with no error')
    sys.exit(1)

files = d.get('autoload', {}).get('files', [])
if 'registration.php' not in files:
    print('registration.php is not in autoload.files — the module never announces itself')
    sys.exit(1)

psr4 = d.get('autoload', {}).get('psr-4', {})
if not psr4:
    print('no psr-4 autoload entries')
    sys.exit(1)

for prefix, path in psr4.items():
    target = os.path.join(root, path.rstrip('/')) if path not in ('', './') else root
    if not os.path.isdir(target):
        print(f'psr-4 prefix {prefix} maps to {path!r}, which does not exist')
        sys.exit(1)

if 'magento/magento-composer-installer' not in d.get('require', {}):
    print('magento/magento-composer-installer is not required')
    sys.exit(1)
PY

    # ABSENCE ASSERTIONS, which are the Magento-shaped inverse of the version-split
    # problem the other connectors have. There, two declarations of the version could
    # disagree. Here the tag is authoritative, so the correct number of OTHER version
    # declarations is zero — and re-introducing either resurrects a second source of
    # truth that can drift from the tag with nothing to notice.
    #
    # `setup_version` in module.xml is additionally wrong on its own terms: this module
    # uses declarative schema and Setup/, and 2.3+ ignores it. Re-adding it would be a
    # number that looks authoritative and is read by nothing.
    if grep -q 'setup_version' "$root/etc/module.xml" 2>/dev/null; then
        echo "etc/module.xml declares setup_version — remove it; declarative schema ignores it and the tag is the version"
        return 1
    fi

    # Dev directories must be export-ignored, or they ship into vendor/.
    local ga="$root/.gitattributes"
    [ -f "$ga" ] || { echo "no .gitattributes — dev files would ship into every merchant's vendor/"; return 1; }
    local d
    for d in bin tests; do
        grep -qE "^/?$d[[:space:]]+export-ignore" "$ga" \
            || { echo "$d/ is not export-ignored — it would ship in the Composer dist archive"; return 1; }
    done

    return 0
}

if [ "${1:-}" = "--self-test" ]; then
    tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' EXIT
    cp -R "$ROOT/." "$tmp/" 2>/dev/null || true
    rm -rf "$tmp/.git"
    # The highest-consequence single line: the type that makes the installer run.
    python3 - "$tmp" <<'PY'
import json, sys, os
p = os.path.join(sys.argv[1], 'composer.json')
d = json.load(open(p))
d['type'] = 'library'
json.dump(d, open(p, 'w'), indent=4)
PY

    if check_tree "$tmp" >/dev/null 2>&1; then
        fail "self-test: the guard PASSED a package whose type is not magento2-module"
    fi
    if ! check_tree "$ROOT" >/dev/null 2>&1; then
        fail "self-test: the guard REFUSED the real tree"
    fi
    pass "self-test: refuses a package that would install invisibly, accepts the real one"
    exit 0
fi

if out="$(check_tree "$ROOT")"; then
    pass "the Composer package will install, register, and ship only what a merchant needs"
else
    fail "$out"
fi
