#!/bin/bash
# Builds PHP 8.0.30 (cli + fpm + opcache) and nginx from source into $PROD_HOME, no root needed.
# Mirrors the production image (docker/base.dockerfile: php:8.0-fpm + bcmath intl opcache zip pdo_mysql exif mysqli gd imap + nginx).
set -euo pipefail
PROD_HOME="${PROD_HOME:-$HOME/development/ost-prod}"
PHP_VER="${PHP_VER:-8.0.30}"
NGINX_VER="${NGINX_VER:-1.26.2}"
B=/opt/homebrew/opt
SRC="$PROD_HOME/src"; mkdir -p "$SRC" "$PROD_HOME"/{php80,nginx,logs,run,tmp}
cd "$SRC"

fetch() { [ -f "$2" ] || curl -fL --retry 3 -o "$2" "$1"; }
fetch "https://www.php.net/distributions/php-$PHP_VER.tar.xz" "php-$PHP_VER.tar.xz" \
  || fetch "https://museum.php.net/php8/php-$PHP_VER.tar.xz" "php-$PHP_VER.tar.xz"
fetch "https://nginx.org/download/nginx-$NGINX_VER.tar.gz" "nginx-$NGINX_VER.tar.gz"

# ---- PHP ----
if [ ! -x "$PROD_HOME/php80/bin/php" ]; then
  [ -d "php-$PHP_VER" ] || tar xf "php-$PHP_VER.tar.xz"
  cd "php-$PHP_VER"
  # No pkg-config on this Mac: pass library flags explicitly (Homebrew libs are read-only for us; we only link them).
  SDK="$(xcrun --show-sdk-path)"
  export LIBXML_CFLAGS="-I$SDK/usr/include/libxml2" LIBXML_LIBS="-lxml2"
  export ZLIB_CFLAGS="-I$SDK/usr/include" ZLIB_LIBS="-lz"
  export CURL_CFLAGS="-I$SDK/usr/include" CURL_LIBS="-lcurl"
  export ONIG_CFLAGS="-I$B/oniguruma/include" ONIG_LIBS="-L$B/oniguruma/lib -lonig"
  export LIBZIP_CFLAGS="-I$B/libzip/include" LIBZIP_LIBS="-L$B/libzip/lib -lzip"
  export PNG_CFLAGS="-I$B/libpng/include" PNG_LIBS="-L$B/libpng/lib -lpng"
  export FREETYPE2_CFLAGS="-I$B/freetype/include/freetype2" FREETYPE2_LIBS="-L$B/freetype/lib -lfreetype"
  export JPEG_CFLAGS="-I$B/libjpeg-turbo/include" JPEG_LIBS="-L$B/libjpeg-turbo/lib -ljpeg"
  export WEBP_CFLAGS="-I$B/webp/include" WEBP_LIBS="-L$B/webp/lib -lwebp"
  export OPENSSL_CFLAGS="-I$B/openssl@3/include" OPENSSL_LIBS="-L$B/openssl@3/lib -lssl -lcrypto"
  # intl is skipped: PHP 8.0 cannot build against the ICU 78 available here (osTicket falls back gracefully).
  ./configure --prefix="$PROD_HOME/php80" --with-config-file-path="$PROD_HOME/php80/etc" \
    --with-config-file-scan-dir="$PROD_HOME/php80/etc/conf.d" \
    --enable-fpm --enable-mbstring --enable-bcmath --enable-exif --enable-opcache \
    --with-mysqli=mysqlnd --with-pdo-mysql=mysqlnd --with-zip --with-openssl \
    --with-curl --with-zlib --with-gettext="$B/gettext" --with-iconv \
    --enable-gd --with-jpeg --with-freetype --with-webp \
    --without-sqlite3 --without-pdo-sqlite --disable-cgi --without-pear --disable-phpdbg 2>&1 | tail -6
  make -j"$(sysctl -n hw.ncpu)" 2>&1 | tail -5
  make install 2>&1 | tail -3
  cd "$SRC"
fi

# ---- nginx ----
if [ ! -x "$PROD_HOME/nginx/sbin/nginx" ]; then
  [ -d "nginx-$NGINX_VER" ] || tar xf "nginx-$NGINX_VER.tar.gz"
  cd "nginx-$NGINX_VER"
  ./configure --prefix="$PROD_HOME/nginx" --with-cc-opt="-I$B/pcre2/include" --with-ld-opt="-L$B/pcre2/lib" \
    --with-http_realip_module 2>&1 | tail -3
  make -j"$(sysctl -n hw.ncpu)" 2>&1 | tail -3
  make install 2>&1 | tail -2
  cd "$SRC"
fi
echo BUILD-STAGE-DONE
"$PROD_HOME/php80/bin/php" -v | head -1
