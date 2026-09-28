#!/bin/bash
# OPT-IN local pre-commit hook: a commit that touches ost-workflow/ or prod-sandbox/ runs ci-check.sh and is refused when it fails.
# Rule (incident A, 2026-09-28): no ost-workflow commit is a valid baseline if ci-check.sh does not pass.
# Non-destructive: it only reads the staged file list and runs the static guards; `git commit --no-verify` skips it.
#   install-git-hooks.sh            install    |    install-git-hooks.sh --remove
REPO="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"; HOOK="$REPO/.git/hooks/pre-commit"
MARK="# ost-workflow ci-check hook"
if [ "$1" = "--remove" ]; then
  [ -f "$HOOK" ] && grep -q "$MARK" "$HOOK" && rm "$HOOK" && echo "removed $HOOK" || echo "nothing to remove"; exit 0
fi
if [ -e "$HOOK" ] && ! grep -q "$MARK" "$HOOK"; then echo "a different pre-commit hook exists at $HOOK; not touching it"; exit 1; fi
cat > "$HOOK" <<'HOOK'
#!/bin/bash
# ost-workflow ci-check hook
if git diff --cached --name-only | grep -qE '^(ost-workflow|prod-sandbox)/'; then
  "$(git rev-parse --show-toplevel)/prod-sandbox/ci-check.sh" >/dev/null || {
    echo "pre-commit: ci-check.sh FAILED - fix it (run prod-sandbox/ci-check.sh) before committing ost-workflow changes" >&2; exit 1; }
fi
HOOK
chmod +x "$HOOK" && echo "installed $HOOK"
