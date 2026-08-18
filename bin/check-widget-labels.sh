#!/usr/bin/env bash
#
# Guard the storefront widget's label catalogues.
#
# The search panel a shopper reads is drawn by a shared bundle that carries no
# locales: it renders English unless this module sends `cfg.labels`. Nothing
# fails when a label is missing — the bundle falls back to its own English, per
# key, and the panel looks completely correct to anyone who reads English. The
# only symptom is a shopper in Bucharest reading a word nobody chose for them.
# There is no crash to catch here, so the catalogues have to be checked directly.
#
#   bin/check-widget-labels.sh              # check the working tree
#   bin/check-widget-labels.sh --self-test  # prove the checks still bite
#
# ⚠ TWO MODES, AND THE DIFFERENCE IS STATED RATHER THAN HIDDEN. The strongest
# check — do the committed catalogues still match the reviewed gettext sources
# they were derived from — needs the sibling checkouts, which exist on a
# developer's machine and not in CI. A guard that quietly downgrades to nothing
# when its input is missing is the failure this project keeps finding, so the
# checks that DO work standalone always run, the run says which mode it was in,
# and an empty catalogue set is an error rather than a green zero.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LABELS="$ROOT/lib/Storefront/labels"

FAILED=0
pass() { printf '  \033[32mok\033[0m   %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; FAILED=1; }
note() { printf '  --   %s\n' "$1"; }

# Every catalogue's key set, shapes and emptiness — all answerable from this
# repo alone. Prints one line per problem; silent when the tree is good.
inspect() { # $1 = labels dir
  php -r '
    $dir = $argv[1];
    $files = glob($dir . "/*.php");
    $cats = [];
    foreach ($files as $f) {
      $name = basename($f, ".php");
      $v = include $f;
      if (! is_array($v) || ! $v) { echo "$name: not an array of labels\n"; continue; }
      $cats[$name] = $v;
    }
    if (! $cats) { echo "no catalogues found in $dir\n"; exit; }

    // The key set is compared ACROSS catalogues rather than against a list kept
    // here: a second list would be the thing that drifts. They all come from one
    // generator, so any disagreement is a defect whichever side it is on.
    $reference = null; $refName = "";
    foreach ($cats as $name => $labels) {
      $keys = array_keys($labels); sort($keys);
      if ($reference === null) { $reference = $keys; $refName = $name; continue; }
      if ($keys !== $reference) {
        $missing = array_diff($reference, $keys);
        $extra = array_diff($keys, $reference);
        echo "$name disagrees with $refName about which keys exist"
          . ($missing ? " (missing: " . implode(",", $missing) . ")" : "")
          . ($extra ? " (extra: " . implode(",", $extra) . ")" : "") . "\n";
      }
    }

    foreach ($cats as $name => $labels) {
      foreach ($labels as $key => $value) {
        if (is_array($value)) {
          if (! isset($value["other"])) { echo "$name: plural $key has no \"other\" form\n"; }
          foreach ($value as $cat => $text) {
            if (! is_string($text) || $text === "") { echo "$name: plural $key/$cat is empty\n"; }
          }
          continue;
        }
        if (! is_string($value) || $value === "") { echo "$name: $key is empty\n"; }
      }
    }
    echo "COUNT:" . count($cats) . ":" . count($reference) . "\n";
  ' "$1"
}

if [ "${1:-}" = "--self-test" ]; then
  TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
  mkdir -p "$TMP/good" "$TMP/bad" "$TMP/empty"
  for L in aa_AA bb_BB; do
    printf '<?php\nreturn ["one_key" => "x", "plural" => ["one" => "a", "other" => "b"]];\n' \
      > "$TMP/good/$L.php"
  done
  cp "$TMP/good/aa_AA.php" "$TMP/bad/aa_AA.php"
  # Three defects at once: a key the sibling does not have, an empty string, and
  # a plural map with no "other" — each the shape of a real regression.
  printf '<?php\nreturn ["one_key" => "", "plural" => ["one" => "a"], "surprise" => "z"];\n' \
    > "$TMP/bad/bb_BB.php"

  # ⚠ NO QUIET-MATCH PIPELINES BELOW. Under `pipefail`, piping a producer into a
  # matcher that exits on its first hit reports FAILURE when the pattern MATCHES:
  # the matcher leaves, the producer takes SIGPIPE and exits 141, and pipefail
  # returns the 141. This project has shipped two releases over exactly that.
  # The output is already in a variable, so match the variable with `case` and
  # keep the pipeline out of it entirely.
  OUT="$(inspect "$TMP/good")"
  case "$OUT" in
    *COUNT:2:2*) ;;
    *) echo "selftest FAILED: did not count 2 catalogues of 2 keys:"; printf '%s\n' "$OUT"; exit 1 ;;
  esac
  NOISE="$(printf '%s\n' "$OUT" | grep -vc '^COUNT:' || true)"
  [ "$NOISE" = "0" ] \
    || { echo "selftest FAILED: a good tree was reported broken:"; printf '%s\n' "$OUT"; exit 1; }

  OUT="$(inspect "$TMP/bad")"
  for expect in "disagrees with" "is empty" "has no \"other\" form"; do
    case "$OUT" in
      *"$expect"*) ;;
      *) echo "selftest FAILED: '$expect' went undetected"; printf '%s\n' "$OUT"; exit 1 ;;
    esac
  done

  OUT="$(inspect "$TMP/empty")"
  case "$OUT" in
    *"no catalogues found"*) ;;
    *) echo "selftest FAILED: an empty directory did not report as empty"; exit 1 ;;
  esac

  echo "selftest ok: key drift, an empty string, a plural with no fallback and an empty set are all caught"
  exit 0
fi

echo "Widget label checks"

if [ ! -d "$LABELS" ]; then
  fail "lib/Storefront/labels is missing — the storefront panel would be English everywhere"
  echo; echo "Widget label checks FAILED."; exit 1
fi

REPORT="$(inspect "$LABELS")"
COUNT_LINE="$(printf '%s\n' "$REPORT" | grep '^COUNT:' || true)"
PROBLEMS="$(printf '%s\n' "$REPORT" | grep -v '^COUNT:' | grep -v '^$' || true)"

if [ -n "$PROBLEMS" ]; then
  printf '%s\n' "$PROBLEMS" | while IFS= read -r line; do fail "$line"; done
  FAILED=1
fi

if [ -z "$COUNT_LINE" ]; then
  fail "no catalogues were inspected — this run proves nothing"
else
  CATS="$(printf '%s' "$COUNT_LINE" | cut -d: -f2)"
  KEYS="$(printf '%s' "$COUNT_LINE" | cut -d: -f3)"
  if [ "$CATS" -lt 10 ]; then
    fail "only $CATS catalogue(s) present — 23 ship, so something has removed them"
  else
    pass "$CATS catalogues, $KEYS keys each, all agreeing on the key set"
  fi
fi

# The strongest check, when its inputs are here.
if [ -d "$ROOT/../plugin/languages" ] && [ -f "$ROOT/../backend/widget/src/widget.jsx" ]; then
  if php "$ROOT/bin/sync-widget-labels.php" --check >/dev/null 2>&1; then
    pass "catalogues match the reviewed gettext sources they were derived from"
  else
    fail "catalogues have drifted from the reviewed sources — run: php bin/sync-widget-labels.php"
    php "$ROOT/bin/sync-widget-labels.php" --check 2>&1 | sed 's/^/       /' || true
  fi
else
  note "sibling checkouts absent — drift against the reviewed sources NOT checked here"
  note "  (that check runs on a developer machine and before a release, not in CI)"
fi

echo
if [ "$FAILED" -ne 0 ]; then
  echo "Widget label checks FAILED."
  exit 1
fi
echo "All widget label checks passed."
