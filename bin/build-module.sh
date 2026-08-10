#!/usr/bin/env bash
#
# Validate the package and produce the release archive.
#
#   ./bin/build-module.sh
#
# WHAT "BUILD" MEANS FOR A COMPOSER PACKAGE, WHICH IS NOT WHAT IT MEANS FOR THE OTHER
# THREE CONNECTORS. They compile a ZIP a merchant uploads through a back office, and
# the ZIP is the product. Here the product is a GIT TAG: `composer require` resolves it
# and downloads a dist archive that GitHub builds from the tree, filtered by
# `.gitattributes`. Nothing this script writes is what a merchant installs.
#
# SO THE ARCHIVE IT PRODUCES IS FOR PEOPLE, NOT FOR COMPOSER — attached to the GitHub
# release for anyone who wants to read or vendor the module without Composer. The
# VALIDATION is the real output, and it runs whether or not the archive is wanted.
#
# THE GUARD LIST IS DERIVED, NOT WRITTEN DOWN. It could name the four checks that exist
# today, and a fifth added beside them would sit in bin/ running on nobody's machine
# while this script printed "Guards" and passed. Every bin/check-*.sh is a release gate
# by construction, so adding one is one file rather than one file and a line here that
# is easy to forget. This is [D-034]'s derive-don't-enumerate rule, which this project
# has now been bitten by four times.
#
# EVERY GUARD SELF-TESTS BEFORE IT IS TRUSTED. A guard that has quietly stopped
# discriminating is worse than no guard, because it reads as coverage. The build
# watches each one fail on purpose before believing it about this tree.
#
set -euo pipefail

cd "$(dirname "$0")/.."

OUT="dist"
mkdir -p "$OUT"

say() { printf '\n\033[1;36m▶ %s\033[0m\n' "$*"; }
ok()  { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }
die() { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

# ⚠ DECLARED, NOT ASSUMED. Several checks in bin/ parse JSON with python3, and the
# read below swallows its own failure with `|| true` — so an absent interpreter
# produced an empty VERSION and let the build carry on to fail somewhere else, in
# the voice of the module rather than of the toolchain. GitHub's runners ship
# python3 and never showed this; a bare php container does.
command -v python3 >/dev/null 2>&1 || die "python3 is required by this build (bin/check-*.sh parse JSON with it) and is not on PATH — a missing tool, not a problem with the module"

VERSION="$(python3 -c "import json;print(json.load(open('composer.json')).get('version',''))" 2>/dev/null || true)"

# ── The version lives in the TAG, not in composer.json ───────────────────────
#
# Packagist derives a package's version from the git tag, and a `version` field in
# composer.json that disagrees with the tag is a documented source of confusion —
# Composer's own docs ask libraries not to set it. So this refuses one rather than
# maintaining a second copy of a number that already exists.
[ -z "$VERSION" ] \
    || die "composer.json declares \"version\": \"$VERSION\" — remove it; the git tag is the version"
ok "the version lives in the tag, not in composer.json"

# ── Stale archives go before anything can fail ───────────────────────────────
rm -f "$OUT"/nitrosearch-magento-*.zip

# ── Lint everything that ships ───────────────────────────────────────────────
#
# The OpenCart module shipped a parse error to merchants once: it had no lint, the
# build exited 0, and the unparseable file was a fatal on the first request that
# autoloaded it. This is that lesson, inherited before it can be repeated.
say "Lint"
command -v php >/dev/null 2>&1 \
    || die "php is not on PATH — refusing to build unlinted (a sibling module shipped a parse error exactly here)"

_linted=0
while IFS= read -r file; do
    php -l "$file" >/dev/null 2>&1 || die "PHP syntax error in $file"
    _linted=$((_linted + 1))
done < <(find . -name '*.php' -not -path './dist/*' -not -path './.git/*' 2>/dev/null)
[ "$_linted" -ge 20 ] || die "only ${_linted} PHP files linted — the find matched less than expected"
ok "${_linted} PHP files parse"

# ── XML, which Magento reads and merchants never see fail until runtime ──────
#
# An invalid layout XML does not fail at install: Magento renders an EXCEPTION PAGE
# FOR THE WHOLE STORE at the first request. That happened in this repo during
# development — a <block> inside <head>, which the schema forbids — and it took a
# 7,405-byte storefront to notice. Well-formedness is not schema validity, but it is
# the half that can be checked without a Magento.
# ⚠ PARSED WITH PHP, NOT WITH `python3`. It was python3 until 2026-08-10, and the
# failure mode is the one this repo keeps writing down: when the interpreter was
# simply ABSENT the command failed, and the guard reported **"malformed XML in
# etc/db_schema.xml"** — naming a real file, accusing it of a defect it did not
# have, and giving no hint that nothing had parsed anything. It reads exactly like
# the store-breaking bug described above. GitHub's runners ship python3 so CI never
# saw it; a bare `php:8.4-cli` container does not, and that is where it appeared.
#
# PHP is the one interpreter a PHP module's build may assume, so this now has no
# undeclared dependency to be absent. `libxml_use_internal_errors` keeps libxml's
# warnings off stderr; the exit code is the whole answer.
say "XML"
_xml=0
while IFS= read -r file; do
    php -r 'libxml_use_internal_errors(true); exit(simplexml_load_file($argv[1]) === false ? 1 : 0);' "$file" >/dev/null 2>&1 \
        || die "malformed XML in $file"
    _xml=$((_xml + 1))
done < <(find etc view -name '*.xml' 2>/dev/null)
[ "$_xml" -ge 10 ] || die "only ${_xml} XML files checked — the find matched less than expected"
ok "${_xml} XML files are well-formed"

# ── Guards ───────────────────────────────────────────────────────────────────
say "Guards"
_guards_run=0
for guard in ./bin/check-*.sh; do
    [ -f "$guard" ] || continue
    _guards_run=$((_guards_run + 1))
    "$guard" --self-test >/dev/null \
        || die "$(basename "$guard") failed its own self-test — fix the guard before trusting this build"
    "$guard" >/dev/null \
        || die "$(basename "$guard") refused this tree — run it directly to see why"
    ok "$(basename "$guard")"
done

# A loop over nothing passes, and "Guards" would print above it. The floor is
# DERIVED from what is on disk rather than hardcoded: a hardcoded 4 keeps passing
# after a fifth guard is added, so a guard that exists but silently fails to run
# would be invisible — which is the same enumeration mistake one level up.
_guards_present="$(find ./bin -maxdepth 1 -name 'check-*.sh' -type f 2>/dev/null | wc -l | tr -d ' ')"
[ "$_guards_run" -eq "$_guards_present" ] && [ "$_guards_run" -ge 4 ] \
    || die "${_guards_run} guard(s) ran but ${_guards_present} exist on disk (floor 4) — this build is not gated"

# ── The conformance suite ────────────────────────────────────────────────────
#
# A module that cannot reproduce the service's pinned HMAC vector cannot talk to it at
# all, and finding that out at package time beats finding it out as a 401 on a
# merchant's store.
say "Conformance"
php tests/run.php || die "the conformance suite failed"

# ── The archive, for humans ──────────────────────────────────────────────────
say "Archive"
TAG="$(git describe --tags --exact-match 2>/dev/null || echo 'untagged')"
NAME="nitrosearch-magento-${TAG}.zip"

# `git archive` applies .gitattributes export-ignore, so what this produces is
# byte-for-byte what Composer's dist archive will contain. Building it any other way
# would test a different tree than the one merchants get.
git archive --format=zip --prefix="nitrosearch-magento/" -o "$OUT/$NAME" HEAD \
    || die "git archive failed — is the tree committed?"

ok "$OUT/$NAME"
printf '\n\033[1;32m▶ Package validated.\033[0m Composer installs from the TAG; the archive is for humans.\n'
