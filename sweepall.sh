#!/usr/bin/env bash
#
# sweepall.sh — auto-format every orange package's src/ in one shot.
#
# For each package listed below it runs, over that package's src/ .php files:
#   1. phpcbf   (lint:fix — PSR-12 auto-format)
#   2. rector   (rector:fix — code-quality / up-to-PHP-8.4 refactors)
#
# It uses each package's own phpcs.xml / rector.php when present, so the result
# matches that package's sweep.sh. Files are edited in place. It does NOT run
# phpstan or the test suites — this is a formatter, not the full gauntlet.
#
# Personal convenience script — keep it gitignored.
#
set -uo pipefail

# --- edit these as needed ----------------------------------------------------

# absolute path to the orange packages directory
ORANGE_DIR="/Users/dmyers/Docker/webapp/vendor/orange"

# package folders (under ORANGE_DIR) to format — add/remove lines freely
packages=(
  acl
  asset
  auth
  benchmark
  bitwise
  cache
  collector
  console
  cookies
  disc
  dto
  fig
  files
  flashmsg
  handlebars
  language
  mergeview
  model
  negotiate
  observer
  priority
  session
  snippets
  stash
  validate
)

# -----------------------------------------------------------------------------

# shared tools the webapp installs into vendor/bin (ORANGE_DIR is …/vendor/orange,
# so its sibling is …/vendor/bin); export BIN_DIR before running to override.
BIN_DIR="${BIN_DIR:-$ORANGE_DIR/../bin}"

for pkg in "${packages[@]}"; do
  src="$ORANGE_DIR/$pkg/src"

  if [ ! -d "$src" ]; then
    echo "==> $pkg — no src/, skipping"
    continue
  fi

  echo ""
  echo "==> $pkg — lint:fix"
  if [ -f "$ORANGE_DIR/$pkg/phpcs.xml" ]; then
    "$BIN_DIR/phpcbf" --standard="$ORANGE_DIR/$pkg/phpcs.xml" "$src" || true
  else
    "$BIN_DIR/phpcbf" --standard=PSR12 --extensions=php "$src" || true
  fi

  echo "==> $pkg — rector:fix"
  if [ -f "$ORANGE_DIR/$pkg/rector.php" ]; then
    "$BIN_DIR/rector" process "$src" --config="$ORANGE_DIR/$pkg/rector.php" || true
  else
    echo "    (no rector.php in $pkg, skipping rector)"
  fi
done

echo ""
echo "sweepall complete."
