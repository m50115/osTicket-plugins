#!/bin/bash
# Production-like runtime for osTicket + ost-workflow, no root, no Docker.
#   php-fpm 8.0.30 + opcache (php.ini-production)  <-  nginx with the REAL docker/default.conf  <-  ELB-like front proxy
# Same app dir and MySQL as the existing sandbox (~/development/ost-sandbox), so data is shared.
#
#   prod-sandbox.sh start [current|proposed]   current = default.conf exactly as in production today
#                                              proposed = + /api/workflow route (PC-W1) + client_max_body_size (PC-W2)
#   prod-sandbox.sh stop | status | restart-ecs [variant] | logs
#
# Ports: :8090 ELB-like front (adds X-Forwarded-For)   :8091 container nginx   :9080 php-fpm
set -euo pipefail
PROD_HOME="${PROD_HOME:-$HOME/development/ost-prod}"
SANDBOX="${SANDBOX_HOME:-$HOME/development/ost-sandbox}"
APP="${APP_DIR:-$SANDBOX/app}"
CORE_REPO="${CORE_REPO:-/Users/Shared/www/osTickets}"
HERE="$(cd "$(dirname "$0")" && pwd)"
PHP="$PROD_HOME/php80/bin/php"; FPM="$PROD_HOME/php80/sbin/php-fpm"; NGINX="$PROD_HOME/nginx/sbin/nginx"
RUN="$PROD_HOME/run"; LOGS="$PROD_HOME/logs"; CONF="$PROD_HOME/conf"
mkdir -p "$RUN" "$LOGS" "$CONF" "$PROD_HOME/php80/etc/conf.d" "$PROD_HOME/tmp"

gen_conf() {
  local variant="${1:-current}"
  # default.conf of the production image, with only the docroot and the fpm port adapted.
  sed -e "s#root /var/www/html;#root $APP;#" -e "s#fastcgi_pass   127.0.0.1:9000;#fastcgi_pass   127.0.0.1:9080;#" \
      "$CORE_REPO/docker/default.conf" > "$CONF/origin.conf"
  sed -i '' 's#listen 80;#listen 8091;#' "$CONF/origin.conf"
  cp "$PROD_HOME/nginx/conf/fastcgi_params" "$CONF/fastcgi_params"
  if [ "$variant" = "proposed" ]; then
    # PC-W1: route /api/workflow to http.php exactly like /api/mobile. PC-W2: explicit body limit.
    python3 - "$CONF/origin.conf" <<'PY'
import sys
p=sys.argv[1]; s=open(p).read()
s=s.replace('(?:tickets|tasks|mobile)','(?:tickets|tasks|mobile|workflow)')
s=s.replace('\tkeepalive_timeout 70;','\tkeepalive_timeout 70;\n\tclient_max_body_size 8m;')
open(p,'w').write(s)
PY
  fi
  cat > "$CONF/nginx.conf" <<NG
worker_processes 1;
pid $RUN/nginx.pid;
error_log $LOGS/nginx-error.log;
events { worker_connections 256; }
http {
  include $CORE_REPO/docker/mime.types;
  default_type application/octet-stream;
  access_log $LOGS/nginx-access.log;
  client_body_temp_path $PROD_HOME/tmp/body; proxy_temp_path $PROD_HOME/tmp/proxy; fastcgi_temp_path $PROD_HOME/tmp/fcgi;
  uwsgi_temp_path $PROD_HOME/tmp/uwsgi; scgi_temp_path $PROD_HOME/tmp/scgi;
  # ELB-like front: terminates the client connection and appends X-Forwarded-For.
  server {
    listen 8090;
    client_max_body_size 0;   # an ALB does not limit request bodies
    location / {
      proxy_pass http://127.0.0.1:8091;
      proxy_set_header Host \$http_host;
      proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
      proxy_set_header X-Forwarded-Proto \$scheme;
    }
  }
  # "Container" nginx: the production default.conf.
  include $CONF/origin.conf;
}
NG
  cat > "$PROD_HOME/php80/etc/php.ini" <<INI
; Production image: php.ini-production, opcache from docker-php-ext-install
$(cat "$PROD_HOME/src/php-8.0.30/php.ini-production")
zend_extension=opcache
opcache.enable=1
pcre.jit=0
error_log=$LOGS/php-error.log
INI
  cat > "$PROD_HOME/php80/etc/php-fpm.conf" <<FPM
[global]
pid = $RUN/php-fpm.pid
error_log = $LOGS/php-fpm.log
daemonize = yes
[www]
listen = 127.0.0.1:9080
pm = dynamic
pm.max_children = 5
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
clear_env = no
catch_workers_output = yes
FPM
}

start() {
  local variant="${1:-current}"
  [ -x "$PHP" ] || { echo "PHP 8.0 not built: run build-php80.sh"; exit 1; }
  gen_conf "$variant"
  mkdir -p "$PROD_HOME/tmp"/{body,proxy,fcgi,uwsgi,scgi}
  "$FPM" -y "$PROD_HOME/php80/etc/php-fpm.conf" -c "$PROD_HOME/php80/etc/php.ini"
  "$NGINX" -c "$CONF/nginx.conf" -p "$PROD_HOME/nginx"
  # Outgoing mail capture (SMTP :1025, UI http://127.0.0.1:8025); osTicket's SMTP account must point to it.
  if [ -x "$PROD_HOME/mailpit/mailpit" ] && ! curl -s -o /dev/null http://127.0.0.1:8025/api/v1/info; then
    (nohup "$PROD_HOME/mailpit/mailpit" --smtp 127.0.0.1:1025 --listen 127.0.0.1:8025 > "$LOGS/mailpit.log" 2>&1 &)
  fi
  echo "started ($variant): front http://127.0.0.1:8090  origin http://127.0.0.1:8091  $($PHP -r 'echo PHP_VERSION;')"
}
stop() {
  [ -f "$RUN/nginx.pid" ] && kill "$(cat "$RUN/nginx.pid")" 2>/dev/null || true
  [ -f "$RUN/php-fpm.pid" ] && kill "$(cat "$RUN/php-fpm.pid")" 2>/dev/null || true
  sleep 1; echo stopped
}
status() {
  for u in 8090 8091; do printf ":%s -> " $u; curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1:$u/scp/login.php" || echo down; done
}
case "${1:-}" in
  start) start "${2:-current}";;
  stop) stop;;
  status) status;;
  restart-ecs) stop; start "${2:-current}";;   # new "container": fresh php-fpm (cold opcache) + nginx
  logs) tail -n 30 "$LOGS"/*.log;;
  *) sed -n 2,12p "$0";;
esac
