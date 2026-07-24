#!/usr/bin/env bash
#
# sweep-all.sh - run a quality sweep across every vendor/orange/* package and
# print a red/green matrix.
#
#   ./sweep-all.sh          read-only status matrix (phpcs check, rector
#                           dry-run, phpstan, tests) - changes nothing
#   ./sweep-all.sh --fix    run each package's own ./sweep.sh (phpcbf + rector
#                           + phpstan + tests) - WRITES fixes into the packages
#
# Tools come from the webapp's vendor/bin; each package is analysed from its own
# directory so its phpcs.xml / phpstan.neon / rector.php apply.
#
set -uo pipefail

cd "$(dirname "$0")"
ROOT="$PWD"
BIN="$ROOT/vendor/bin"
ORANGE="$ROOT/vendor/orange"
MODE="${1:-status}"

if [ ! -d "$ORANGE" ]; then
  echo "vendor/orange not found - run composer install first." >&2
  exit 1
fi

# --- fix mode: defer to each package's own gauntlet ---------------------------
if [ "$MODE" = "--fix" ]; then
  failed=()
  for dir in "$ORANGE"/*/; do
    pkg=$(basename "$dir")
    [ -x "$dir/sweep.sh" ] || continue
    echo ""
    echo "==================== $pkg ===================="
    if ! ( cd "$dir" && ./sweep.sh ); then
      failed+=("$pkg")
    fi
  done
  echo ""
  if [ ${#failed[@]} -eq 0 ]; then
    echo "All package sweeps passed."
  else
    echo "FAILED: ${failed[*]}" >&2
    exit 1
  fi
  exit 0
fi

# --- status mode: read-only matrix -------------------------------------------
printf '%-14s %-8s %-9s %-9s %-6s\n' PACKAGE PHPCS RECTOR PHPSTAN TESTS
printf '%-14s %-8s %-9s %-9s %-6s\n' '-------' '-----' '------' '-------' '-----'

overall=0
for dir in "$ORANGE"/*/; do
  pkg=$(basename "$dir")
  [ -f "$dir/phpstan.neon" ] || [ -f "$dir/phpcs.xml" ] || continue

  # phpcs (check only)
  if ( cd "$dir" && "$BIN/phpcs" -q --report=summary ) >/tmp/sa.pcs 2>&1; then
    pcs="ok"
  else
    n=$(grep -oE 'A TOTAL OF [0-9]+ ERROR' /tmp/sa.pcs | grep -oE '[0-9]+' | head -1)
    pcs="${n:-?} viol"; overall=1
  fi

  # rector (dry-run, never writes)
  ( cd "$dir" && "$BIN/rector" process --dry-run --no-progress-bar ) >/tmp/sa.rec 2>&1
  if grep -q 'would have been changed' /tmp/sa.rec; then
    n=$(grep -oE '\[OK\] [0-9]+ file' /tmp/sa.rec | grep -oE '[0-9]+' | head -1)
    rec="${n:-?} chg"; overall=1
  else
    rec="ok"
  fi

  # phpstan
  if ( cd "$dir" && "$BIN/phpstan" analyse --memory-limit=1G --no-progress ) >/tmp/sa.pst 2>&1; then
    pst="ok"
  else
    n=$(grep -oE 'Found [0-9]+ error' /tmp/sa.pst | grep -oE '[0-9]+' | head -1)
    pst="${n:-?} err"; overall=1
  fi

  # tests (only if the package ships a runner - dir is unittest/ or unittests/)
  tst="-"
  for td in unittest unittests; do
    if [ -f "$dir/$td/runUnitTests.sh" ]; then
      if ( cd "$dir/$td" && sh runUnitTests.sh ) >/tmp/sa.tst 2>&1; then tst="pass"; else tst="FAIL"; overall=1; fi
      break
    fi
  done

  printf '%-14s %-8s %-9s %-9s %-6s\n' "$pkg" "$pcs" "$rec" "$pst" "$tst"
done

rm -f /tmp/sa.pcs /tmp/sa.rec /tmp/sa.pst /tmp/sa.tst
echo ""
[ "$overall" -eq 0 ] && echo "All green." || echo "Some checks need attention (see columns above)."
exit "$overall"
