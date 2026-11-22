#!/usr/bin/env bash
# Builds the PHP the plugin embeds: a static, thread-safe libphp with the embed SAPI, installed in PREFIX.
# Usage: scripts/build-php.sh [prefix]; PHP_VERSION and PHP_SHA256 pick another release.
set -euo pipefail

cd "$(dirname "$0")/.."
: "${PHP_VERSION:=8.3.37}"
: "${PHP_SHA256:=0237af84478b7a7649d3964153f1b8653d622550b846f56d63062de683fb86bb}"
PREFIX="${1:-$PWD/.toolchain/php}"
WORK="$PWD/.toolchain"
SOURCE="$WORK/php-$PHP_VERSION"
JOBS="$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 4)"

mkdir -p "$WORK"
if [ ! -d "$SOURCE" ]; then
    archive="$WORK/php-$PHP_VERSION.tar.xz"
    curl -fsSL -A "pmmp-compat-build" -o "$archive" "https://www.php.net/distributions/php-$PHP_VERSION.tar.xz"
    if command -v sha256sum >/dev/null; then
        echo "$PHP_SHA256  $archive" | sha256sum -c -
    else
        echo "$PHP_SHA256  $archive" | shasum -a 256 -c -
    fi
    tar -xJf "$archive" -C "$WORK"
    rm "$archive"
fi

cd "$SOURCE"
# position independent: the static library ends up inside the plugin's shared library
export CFLAGS="${CFLAGS:--O2} -fPIC"

# what PocketMine-MP plugins expect; mbstring without its regular expressions, which need oniguruma
./configure \
    --prefix="$PREFIX" \
    --enable-embed=static \
    --enable-zts \
    --disable-all \
    --disable-cgi \
    --disable-cli \
    --disable-phpdbg \
    --without-pear \
    --disable-opcache \
    --enable-phar \
    --with-zlib \
    --enable-ctype \
    --enable-tokenizer \
    --enable-filter \
    --enable-mbstring \
    --disable-mbregex \
    --with-curl

make -j"$JOBS"
make install
echo "PHP $PHP_VERSION installed in $PREFIX"
