#!/usr/bin/env bash
# Usage: bash build-client.sh /path/to/Falcon/build /path/to/output/client [client.cpp]
set -euo pipefail

fixture="$(cd "$(dirname "$0")" && pwd)"
build="$(cd "$1" && pwd)"
deps="$build/_deps"
ssl="$(sed -n 's/^OPENSSL_SSL_LIBRARY:FILEPATH=//p' "$build/CMakeCache.txt")"
crypto="$(sed -n 's/^OPENSSL_CRYPTO_LIBRARY:FILEPATH=//p' "$build/CMakeCache.txt")"

"${CXX:-c++}" -std=c++20 -O2 -pthread \
    -I"$deps/falcon_network-src/include" \
    -I"$deps/falcon_protocol-src/include" \
    -I"$deps/falcon_nbt-src/include" \
    "${3:-$fixture/client.cpp}" -o "$2" \
    "$deps/falcon_network-build/libFalconNetwork.a" \
    "$deps/falcon_protocol-build/libFalconProtocol.a" \
    "$deps/falcon_nbt-build/libFalconNBT.a" \
    "$deps/snappy-build/libsnappy.a" \
    "$deps/libdatachannel-build/libdatachannel-static.a" \
    "$deps/libdatachannel-build/deps/usrsctp/usrsctplib/libusrsctp.a" \
    "$deps/libdatachannel-build/deps/libjuice/libjuice-static.a" \
    "$build/Falcon.Server/libFalconTransportTranscript.a" \
    "$ssl" "$crypto" -lz
