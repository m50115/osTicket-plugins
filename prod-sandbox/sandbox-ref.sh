#!/bin/bash
# Reference sandbox for BestCare Workflow (osTicket + ost-workflow). ONE sandbox, no root, no Docker, reproducible from nothing.
#   sandbox-ref.sh setup [phar]   build everything from scratch (refuses if the sandbox already exists)
#   sandbox-ref.sh start|stop|status|sql "<query>"|teardown
#
# Layout (all under $SANDBOX_HOME, outside every repository; secrets only in credentials.env, mode 0600):
#   app/            osTicket core = `git archive` of the production branch (ost-API); NEVER modified (see `verify-core`)
#   db/ mysql.*     MySQL 8.0.43 (toolchain binaries), root without password on the local socket only, TCP 127.0.0.1:$DB_PORT
#   credentials.env SB_DB_PASS SB_ADMIN_USER SB_ADMIN_PASS (generated)
# Runtime = prod-sandbox.sh (php-fpm 8.0 + nginx with the real docker/default.conf, front :8090, origin :8091, fpm :9080).
# The plugin phar is the ONLY thing copied over the core tree (include/plugins/ost-workflow.phar).
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
SANDBOX_HOME="${SANDBOX_HOME:-$HOME/development/ost-sandbox}"
TOOLCHAIN="${TOOLCHAIN:-$HOME/development/toolchain}"
PROD_HOME="${PROD_HOME:-$HOME/development/ost-prod}"
CORE_REPO="${CORE_REPO:-/Users/Shared/www/osTickets}"; CORE_REF="${CORE_REF:-ost-API}"
DB_PORT="${DB_PORT:-3307}"; INSTALL_PORT="${INSTALL_PORT:-8099}"
MYSQL_BIN_DIR="$TOOLCHAIN/mysql/bin"; PHP80="$PROD_HOME/php80/bin/php"
APP="$SANDBOX_HOME/app"; CRED="$SANDBOX_HOME/credentials.env"; SOCK="$SANDBOX_HOME/mysql.sock"
log() { printf '[sandbox-ref] %s\n' "$*"; }
die() { printf '[sandbox-ref] ERROR: %s\n' "$*" >&2; exit 1; }
mysql_root() { "$MYSQL_BIN_DIR/mysql" --no-defaults -S "$SOCK" -uroot "$@"; }
db_ok() { [ -S "$SOCK" ] && mysql_root -e 'select 1' >/dev/null 2>&1; }

start_db() {
  db_ok && return
  [ -d "$SANDBOX_HOME/db/mysql" ] || { mkdir -p "$SANDBOX_HOME/db"
    "$MYSQL_BIN_DIR/mysqld" --no-defaults --initialize-insecure --basedir="$TOOLCHAIN/mysql" --datadir="$SANDBOX_HOME/db" >"$SANDBOX_HOME/mysql-init.log" 2>&1; }
  nohup "$MYSQL_BIN_DIR/mysqld" --no-defaults --basedir="$TOOLCHAIN/mysql" --datadir="$SANDBOX_HOME/db" --socket="$SOCK" \
     --port="$DB_PORT" --bind-address=127.0.0.1 --mysqlx=OFF --pid-file="$SANDBOX_HOME/mysql.pid" \
     --log-error="$SANDBOX_HOME/mysql.log" >/dev/null 2>&1 &
  disown || true
  for _ in $(seq 1 40); do db_ok && return; sleep 1; done
  die "MySQL did not start; see $SANDBOX_HOME/mysql.log"
}
stop_db() { db_ok && "$MYSQL_BIN_DIR/mysqladmin" --no-defaults -S "$SOCK" -uroot shutdown || true; }

cmd_setup() {
  local phar="${1:-}"
  [ -e "$SANDBOX_HOME" ] && die "$SANDBOX_HOME already exists (run teardown first)"
  [ -x "$PHP80" ] && [ -x "$MYSQL_BIN_DIR/mysqld" ] || die "missing toolchain (PHP 8.0 in $PROD_HOME, MySQL in $TOOLCHAIN)"
  [ -z "$phar" ] || [ -f "$phar" ] || die "phar not found: $phar"
  umask 077
  mkdir -p "$SANDBOX_HOME"
  { echo "SB_DB_PASS=$(openssl rand -hex 12)"; echo "SB_ADMIN_USER=ostadmin"; echo "SB_ADMIN_PASS=$(openssl rand -hex 9)aA1!"; } > "$CRED"; chmod 600 "$CRED"
  . "$CRED"
  start_db
  mysql_root -e "CREATE DATABASE osticket CHARACTER SET utf8mb4;
    CREATE USER 'ost'@'127.0.0.1' IDENTIFIED BY '$SB_DB_PASS'; CREATE USER 'ost'@'localhost' IDENTIFIED BY '$SB_DB_PASS';
    GRANT ALL ON osticket.* TO 'ost'@'127.0.0.1'; GRANT ALL ON osticket.* TO 'ost'@'localhost';"
  log "core = git archive $CORE_REF ($(git -C "$CORE_REPO" rev-parse --short=12 "$CORE_REF"), tree $(git -C "$CORE_REPO" rev-parse "$CORE_REF^{tree}"))"
  mkdir -p "$APP"; git -C "$CORE_REPO" archive "$CORE_REF" | tar -x -C "$APP" --exclude=docker
  cp "$APP/include/ost-sampleconfig.php" "$APP/include/ost-config.php"; chmod 666 "$APP/include/ost-config.php"
  # official installer through the PHP 8.0 built-in server (no extra deps), then removed
  local ini="$SANDBOX_HOME/install-php.ini"; printf 'memory_limit=256M\nerror_reporting=22527\ndisplay_errors=0\nlog_errors=1\nerror_log=%s/php-error.log\n' "$SANDBOX_HOME" > "$ini"
  ( cd "$APP" && exec nohup "$PHP80" -n -c "$ini" -S "127.0.0.1:$INSTALL_PORT" router.php ) </dev/null >"$SANDBOX_HOME/php-install.log" 2>&1 &
  local pid=$!; sleep 2
  local j="$SANDBOX_HOME/cj.txt" u="http://127.0.0.1:$INSTALL_PORT/setup/install.php"
  curl -s -c "$j" -b "$j" -o /dev/null -X POST -d "s=prereq" "$u"
  curl -s -c "$j" -b "$j" -X POST "$u" --data-urlencode "s=install" --data-urlencode "name=BestCare Reference Sandbox" \
    --data-urlencode "email=helpdesk@example.com" --data-urlencode "lang_id=en_US" --data-urlencode "fname=Admin" --data-urlencode "lname=Local" \
    --data-urlencode "admin_email=admin@example.com" --data-urlencode "username=$SB_ADMIN_USER" --data-urlencode "passwd=$SB_ADMIN_PASS" \
    --data-urlencode "passwd2=$SB_ADMIN_PASS" --data-urlencode "prefix=ost_" --data-urlencode "dbhost=127.0.0.1:$DB_PORT" \
    --data-urlencode "dbname=osticket" --data-urlencode "dbuser=ost" --data-urlencode "dbpass=$SB_DB_PASS" -o "$SANDBOX_HOME/install_out.html"
  kill "$pid" 2>/dev/null || true; rm -f "$j"
  grep -q "Congratulations" "$SANDBOX_HOME/install_out.html" || die "osTicket installer failed; see $SANDBOX_HOME/install_out.html and php-error.log"
  chmod 644 "$APP/include/ost-config.php"; rm -rf "$APP/setup"; rm -f "$SANDBOX_HOME/install_out.html" "$ini"
  [ -z "$phar" ] || { cp "$phar" "$APP/include/plugins/ost-workflow.phar"; log "plugin phar: $(shasum -a 256 "$phar" | cut -c1-16)…"; }
  log "ready. Next: sandbox-ref.sh start; then plugin-admin.php + bootstrap-config.php (see README)"
}

cmd_start() { start_db; SANDBOX_HOME="$SANDBOX_HOME" PROD_HOME="$PROD_HOME" "$HERE/prod-sandbox.sh" start proposed; }
cmd_stop()  { "$HERE/prod-sandbox.sh" stop; stop_db; log "stopped"; }
cmd_status(){ db_ok && mysql_root -N -e 'select concat("db ",version()," :'"$DB_PORT"'")' || echo "db down"; "$HERE/prod-sandbox.sh" status; }
cmd_sql()   { . "$CRED"; MYSQL_PWD="$SB_DB_PASS" "$MYSQL_BIN_DIR/mysql" -h127.0.0.1 -P"$DB_PORT" -uost osticket -e "$1"; }

# core integrity: every file of the app tree equals `git archive $CORE_REF`, except the installer artifacts and the plugin phar
cmd_verify_core() {
  local t; t="$(mktemp -d)"; trap 'rm -rf "$t"' RETURN
  git -C "$CORE_REPO" archive "$CORE_REF" | tar -x -C "$t" --exclude=docker; rm -rf "$t/setup"
  diff -rq "$t" "$APP" | grep -vE 'include/ost-config.php|include/plugins/ost-workflow.phar|include/plugins/\.keep' || echo "core: identical to $CORE_REF (only ost-config.php and the plugin phar differ)"
}

cmd_teardown() {
  [ -d "$SANDBOX_HOME" ] || { log "nothing to remove"; return; }
  case "$SANDBOX_HOME" in */development/ost-sandbox|*/ost-sandbox-*) ;; *) die "refusing to remove an unexpected path: $SANDBOX_HOME";; esac
  "$HERE/prod-sandbox.sh" stop || true; stop_db
  rm -rf "$SANDBOX_HOME"; log "removed $SANDBOX_HOME (toolchain and ost-prod untouched)"
}

case "${1:-}" in
  setup) shift; cmd_setup "$@";; start) cmd_start;; stop) cmd_stop;; status) cmd_status;; sql) cmd_sql "${2:?query}";;
  verify-core) cmd_verify_core;; teardown) cmd_teardown;;
  *) sed -n 2,5p "$0"; exit 2;;
esac
