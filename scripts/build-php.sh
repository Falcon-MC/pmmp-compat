#!/usr/bin/env bash
# Builds the PHP the plugin embeds: a static, thread-safe libphp with the embed SAPI, installed in PREFIX.
# Usage: scripts/build-php.sh [prefix]; PHP_VERSION and PHP_SHA256 pick another release.
# Needs autoconf (pmmpthread is built into PHP, which regenerates configure), and the sqlite3, gmp and openssl
# development files; on macOS gmp and openssl come from Homebrew and are linked in statically.
set -euo pipefail

cd "$(dirname "$0")/.."
: "${PHP_VERSION:=8.3.37}"
: "${PHP_SHA256:=0237af84478b7a7649d3964153f1b8653d622550b846f56d63062de683fb86bb}"
: "${PMMPTHREAD_VERSION:=6.3.0}"
: "${PMMPTHREAD_SHA256:=c9896660c2d8ca56eb66de2ac2805e68aef39431e901b015511d212b052d3d26}"
: "${IGBINARY_VERSION:=3.2.16}"
: "${IGBINARY_SHA256:=941f1cf2ccbecdc1c221dbfae9213439d334be5d490a2f3da2be31e8a00b0cdb}"
PREFIX="${1:-$PWD/.toolchain/php}"
WORK="$PWD/.toolchain"
SOURCE="$WORK/php-$PHP_VERSION"
JOBS="$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 4)"

verify() {
    if command -v sha256sum >/dev/null; then
        echo "$1  $2" | sha256sum -c -
    else
        echo "$1  $2" | shasum -a 256 -c -
    fi
}

mkdir -p "$WORK"
if [ ! -d "$SOURCE" ]; then
    archive="$WORK/php-$PHP_VERSION.tar.xz"
    curl -fsSL -A "pmmp-compat-build" -o "$archive" "https://www.php.net/distributions/php-$PHP_VERSION.tar.xz"
    verify "$PHP_SHA256" "$archive"
    tar -xJf "$archive" -C "$WORK"
    rm "$archive"
fi

# an extension from outside PHP, built into it as its own are: name, version, checksum, archive URL
bundle() {
    local name="$1" version="$2" sum="$3" url="$4"
    if [ -f "$SOURCE/ext/$name/config.m4" ] && grep -qx "$version" "$SOURCE/ext/$name/.version" 2>/dev/null; then
        return
    fi
    local archive="$WORK/$name-$version.tar.gz"
    curl -fsSL -A "pmmp-compat-build" -o "$archive" "$url"
    verify "$sum" "$archive"
    rm -rf "$SOURCE/ext/$name"
    mkdir -p "$SOURCE/ext/$name"
    tar -xzf "$archive" -C "$SOURCE/ext/$name" --strip-components 1
    echo "$version" > "$SOURCE/ext/$name/.version"
    rm "$archive"
    rm -f "$SOURCE/configure"
}

# pmmpthread, PocketMine-MP's threads, and igbinary, which they pass values between threads with
bundle pmmpthread "$PMMPTHREAD_VERSION" "$PMMPTHREAD_SHA256" \
    "https://github.com/pmmp/ext-pmmpthread/archive/refs/tags/$PMMPTHREAD_VERSION.tar.gz"
bundle igbinary "$IGBINARY_VERSION" "$IGBINARY_SHA256" \
    "https://github.com/igbinary/igbinary/archive/refs/tags/$IGBINARY_VERSION.tar.gz"

cd "$SOURCE"
if [ ! -f configure ] || ! grep -q "enable-pmmpthread" configure || ! grep -q "enable-igbinary" configure; then
    ./buildconf --force
fi

# the libraries the plugin cannot expect on a server: linked in from static archives
extra=()
if [ "$(uname)" = "Darwin" ]; then
    brew_prefix="$(brew --prefix)"
    static="$WORK/static"
    rm -rf "$static" && mkdir -p "$static/lib"
    ln -s "$brew_prefix/opt/gmp/include" "$static/include"
    ln -s "$brew_prefix/opt/gmp/lib/libgmp.a" "$static/lib/libgmp.a"
    openssl="$brew_prefix/opt/openssl@3"
    export OPENSSL_CFLAGS="-I$openssl/include"
    export OPENSSL_LIBS="$openssl/lib/libssl.a $openssl/lib/libcrypto.a"
    # the system's own sqlite, which every Mac has
    export SQLITE_CFLAGS=" "
    export SQLITE_LIBS="-lsqlite3"
    extra+=(--with-gmp="$static")
else
    extra+=(--with-gmp)
fi
# position independent: the static library ends up inside the plugin's shared library
export CFLAGS="${CFLAGS:--O2} -fPIC"

# what PocketMine-MP plugins expect; mbstring without its regular expressions, which need oniguruma; sqlite3, PDO
# and mysqli for the plugins that keep data, libasynql's among them
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
    --with-curl \
    --with-openssl \
    --enable-sockets \
    --enable-bcmath \
    --with-sqlite3 \
    --enable-pdo \
    --with-pdo-sqlite \
    --enable-mysqlnd \
    --with-mysqli=mysqlnd \
    --with-pdo-mysql=mysqlnd \
    --enable-pmmpthread \
    --enable-igbinary \
    --with-pmmpthread-sockets \
    "${extra[@]}"

make -j"$JOBS"
make install
# what php-config does not list: the archives given to configure through the environment
echo "${OPENSSL_LIBS:-}" > "$PREFIX/lib/pmmp-extra-libs.txt"
echo "PHP $PHP_VERSION installed in $PREFIX"
