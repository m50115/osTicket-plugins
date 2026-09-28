#!/bin/bash
# DB helper for e2e.py (SANDBOX ONLY): runs one query against the sandbox MySQL and prints the result (header line first;
# e2e.py drops it). No secret lives here: the DB password comes from ~/development/ost-sandbox/credentials.env (SB_DB_PASS).
#   sql.sh "select 1"
# Overrides: SANDBOX_HOME, MYSQL_BIN, SB_DB_HOST, SB_DB_PORT, SB_DB_USER, SB_DB_NAME
SB="${SANDBOX_HOME:-$HOME/development/ost-sandbox}"
set -a; . "$SB/credentials.env"; set +a
MYSQL_BIN="${MYSQL_BIN:-$SB/mysql-8.0.43-macos15-arm64/bin/mysql}"
MYSQL_PWD="$SB_DB_PASS" "$MYSQL_BIN" -h"${SB_DB_HOST:-127.0.0.1}" -P"${SB_DB_PORT:-3307}" -u"${SB_DB_USER:-ost}" "${SB_DB_NAME:-osticket}" -e "$1" 2>&1 | grep -v "Using a password"
exit 0
