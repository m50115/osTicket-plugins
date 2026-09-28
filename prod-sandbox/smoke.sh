#!/bin/bash
# Smoke test of a deployed ost-workflow. Usage: smoke.sh [BASE] (default the prod-like front proxy)
# Needs SB_ADMIN_USER / SB_ADMIN_PASS (sandbox credentials.env) in the environment.
BASE="${1:-http://127.0.0.1:8090/api/workflow/v1}"
ROOT="${BASE%/api/*}"
[ -z "${SB_ADMIN_PASS:-}" ] && . "$HOME/development/ost-sandbox/credentials.env"
ok=0; bad=0
check() { if [ "$2" = "$3" ]; then ok=$((ok+1)); printf "  ok    %s\n" "$1"; else bad=$((bad+1)); printf "  FAIL  %s (expected %s, got %s)\n" "$1" "$2" "$3"; fi; }
code() { curl -s -o /dev/null -w "%{http_code}" "$@"; }
echo "smoke: $BASE"
check "SCP login page answers"            422 "$(code $ROOT/scp/login.php)"
check "portal answers"                    200 "$(code $ROOT/)"
check "GET /ping"                         200 "$(code $BASE/ping)"
check "unknown route is JSON 404"         404 "$(code $BASE/nope)"
check "wrong method is 405"               405 "$(code -X POST $BASE/ping)"
check "no token is 401"                   401 "$(code $BASE/config)"
TOK=$(curl -s -X POST $BASE/auth/login -d "{\"username\":\"$SB_ADMIN_USER\",\"password\":\"$SB_ADMIN_PASS\"}" | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["token"])' 2>/dev/null)
[ -n "$TOK" ] && check "login returns a token" ok ok || check "login returns a token" ok none
H="Authorization: Bearer $TOK"
check "GET /config"                       200 "$(code -H "$H" $BASE/config)"
check "GET /me/permissions"               200 "$(code -H "$H" $BASE/me/permissions)"
check "list requires state (422)"         422 "$(code -H "$H" "$BASE/tickets")"
check "list open"                         200 "$(code -H "$H" "$BASE/tickets?state=open&limit=2")"
check "write without Idempotency-Key"     400 "$(code -X POST -H "$H" -d '{}' $BASE/tickets)"
K=$(uuidgen); B='{"subject":"smoke","message":"smoke","email":"smoke@example.com","name":"Smoke","topic_id":1}'
A=$(curl -s -X POST -H "$H" -H "Idempotency-Key: $K" -d "$B" $BASE/tickets | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["id"])' 2>/dev/null)
R=$(curl -s -X POST -H "$H" -H "Idempotency-Key: $K" -d "$B" $BASE/tickets | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["id"])' 2>/dev/null)
check "create + replay = same ticket"     "$A" "$R"
check "same key, other body is 422"       422 "$(code -X POST -H "$H" -H "Idempotency-Key: $K" -d '{"subject":"other","message":"x","user_id":1,"topic_id":1}' $BASE/tickets)"
check "stale base is 409"                 409 "$(code -X POST -H "$H" -H "Idempotency-Key: $(uuidgen)" -d '{"status_id":3,"base":2}' $BASE/tickets/$A/status)"
check "revoked token is 401"              401 "$(curl -s -X POST -H "$H" $BASE/auth/logout >/dev/null; code -H "$H" $BASE/config)"
echo "result: $ok ok, $bad failed"; [ $bad -eq 0 ]
