#!/bin/bash
# Reproducible-ish artifact: stamps the git commit into Build::SHA, builds with PHP 8.0 in a scratch copy of the
# repo (the working tree is never modified) and writes dist/ost-workflow.phar + .sha256 + build.json.
#   build-artifact.sh
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; REPO="$(cd "$HERE/.." && pwd)"
PHP80="${PHP80:-$HOME/development/ost-prod/php80/bin/php}"; [ -x "$PHP80" ] || PHP80=php
"$HERE/ci-check.sh" >/dev/null || { "$HERE/ci-check.sh"; echo "refusing to build: ci-check failed"; exit 1; }
SHA="$(git -C "$REPO" rev-parse --short=12 HEAD)"; DIRTY=""
[ -n "$(git -C "$REPO" status --porcelain -- ost-workflow)" ] && DIRTY="-dirty"
VER="$(grep -o "'version' *=> *'[^']*'" "$REPO/ost-workflow/plugin.php" | sed "s/.*'\(.*\)'/\1/")"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
rsync -a --exclude .git --exclude '*.phar' --exclude prod-sandbox "$REPO/" "$TMP/"
sed -i '' "s/const SHA = 'dev';/const SHA = '$SHA$DIRTY';/" "$TMP/ost-workflow/lib/OstWorkflow/Build.php"
grep -q "const SHA = '$SHA$DIRTY'" "$TMP/ost-workflow/lib/OstWorkflow/Build.php" || { echo "could not stamp build_sha"; exit 1; }
( cd "$TMP" && "$PHP80" -dphar.readonly=0 make.php build ost-workflow >/dev/null 2>&1 || true )
[ -f "$TMP/ost-workflow.phar" ] || { echo "build failed"; exit 1; }
mkdir -p "$HERE/dist"; cp "$TMP/ost-workflow.phar" "$HERE/dist/ost-workflow.phar"
( cd "$HERE/dist" && shasum -a 256 ost-workflow.phar > ost-workflow.phar.sha256 )
printf '{"plugin_version":"%s","build_sha":"%s%s","built":"%s","php":"%s"}\n' "$VER" "$SHA" "$DIRTY" "$(date -u +%FT%TZ)" "$("$PHP80" -r 'echo PHP_VERSION;')" > "$HERE/dist/build.json"
"$HERE/ci-check.sh" "$HERE/dist/ost-workflow.phar" | sed -n '/^phar/,$p'
echo "built: $HERE/dist/ost-workflow.phar  ($(cat "$HERE/dist/build.json"))"
