#!/bin/bash
# Static guards for ost-workflow (run before every build/deploy). Exit 1 on any failure.
#   ci-check.sh [path/to/ost-workflow.phar]
# Encodes the rules that kept `mobile` from taking the site down (Technical Notes PP-01/05/12/13, R-C02/R-C07/R-C14).
HERE="$(cd "$(dirname "$0")" && pwd)"; SRC="$HERE/../ost-workflow"
PHP80="${PHP80:-$HOME/development/ost-prod/php80/bin/php}"; [ -x "$PHP80" ] || PHP80=php
fail=0
ok()  { printf "  ok    %s\n" "$1"; }
bad() { printf "  FAIL  %s\n" "$1"; [ -n "$2" ] && echo "$2" | sed 's/^/          /'; fail=1; }

echo "ci-check: $SRC (php $($PHP80 -r 'echo PHP_VERSION;'))"
out=$(find "$SRC" -name '*.php' -print0 | xargs -0 -n1 "$PHP80" -l 2>&1 | grep -v '^No syntax errors')
[ -z "$out" ] && ok "every file lints with PHP 8.0" || bad "syntax errors" "$out"

out=$(grep -rnE '^\s*function\s+[A-Za-z_]' "$SRC" --include=*.php | grep -vE '^\S+:[0-9]+:\s+(static |public |private |protected )?function' | head)
out2=$(grep -rnE '^function ' "$SRC" --include=*.php)
[ -z "$out2" ] && ok "no global functions (R-C02)" || bad "global function declared" "$out2"

out=$(grep -rnE '((=|return|\(|,|\?\?)\s*match\s*\(|\benum\s+[A-Z]|readonly\s+(public|private|protected|\$)|never\s+\w+\(|new\s+\w+\(.*\)\s*;\s*//\s*8\.1)' "$SRC" --include=*.php | grep -vE '^\S+:[0-9]+:\s*(//|\*|/\*)' | head)
[ -z "$out" ] && ok "no PHP >= 8.1 syntax (match/enum/readonly/never)" || bad "post-8.0 syntax" "$out"

out=$(grep -rnE '\b(exit|die)\s*[;(]|Http::response' "$SRC/lib" "$SRC/workflow.php" "$SRC/config.php" --include=*.php | grep -vE ':\s*(//|\*)' | head)
[ -z "$out" ] && ok "no exit/die/Http::response (R-C07: one emitter)" || bad "exit/die/Http::response found" "$out"

out=$(grep -rnE '^\s*(echo|print)\b' "$SRC/lib" --include=*.php | grep -v 'Emitter.php' | head)
[ -z "$out" ] && ok "echo only in the Emitter" || bad "echo outside the Emitter" "$out"

out=$(grep -rnE 'file_put_contents|fopen\([^)]*[\x27"][wax]|__DIR__[^;]*(unlink|mkdir|touch)|tempnam|sys_get_temp_dir' "$SRC" --include=*.php | grep -vE ':\s*(//|\*)' | head)
[ -z "$out" ] && ok "no state in files (PP-12)" || bad "file writes" "$out"

n=$(grep -c "'id'" "$SRC/plugin.php"); id=$(grep "'id'" "$SRC/plugin.php" | sed "s/.*=> *'\(.*\)'.*/\1/")
[ "$n" = "1" ] && [ "$id" = "ost:workflow" ] && ok "one manifest, id ost:workflow (PP-01)" || bad "manifest id is '$id' ($n entries)"

lines=$(grep -cE '^\s*(require|include)(_once)?\b' "$SRC/workflow.php")
[ "$lines" -le 2 ] && ok "main file is minimal ($lines requires; PP-13)" || bad "main file has $lines requires (max 2: class.plugin.php and config.php)"

if [ -n "$1" ]; then
  echo "phar: $1"
  "$PHP80" -d phar.readonly=0 -r '
    $p = new Phar($argv[1]); $files = []; foreach (new RecursiveIteratorIterator($p) as $f) $files[] = str_replace("phar://" . realpath($argv[1]) . "/", "", (string) $f);
    $need = ["plugin.php", "workflow.php", "config.php", "lib/OstWorkflow/Router.php", "lib/OstWorkflow/Pipeline.php"];
    $miss = array_diff($need, $files); $mans = array_filter($files, function ($f) { return basename($f) === "plugin.php"; });
    echo count($miss) ? "FAIL missing: " . implode(",", $miss) . "\n" : "  ok    phar has the code and lib/\n";
    echo count($mans) === 1 ? "  ok    exactly one plugin.php manifest in the phar\n" : "FAIL " . count($mans) . " manifests\n";
    $m = include "phar://" . realpath($argv[1]) . "/plugin.php"; echo ($m["id"] ?? "") === "ost:workflow" ? "  ok    manifest id inside the phar\n" : "FAIL manifest id\n";
    echo count(array_filter($files, function ($f) { return preg_match("~^(docs|tests|\.git)~", $f); })) ? "  note  docs/ are inside the phar (harmless)\n" : "";
  ' "$1" | tee /tmp/ow_ci_phar.txt
  grep -q FAIL /tmp/ow_ci_phar.txt && fail=1
fi
[ $fail -eq 0 ] && echo "ci-check: OK" || echo "ci-check: FAILED"; exit $fail
