#!/usr/bin/env bash
# Usage: bash build-client.sh /path/to/Falcon/build /path/to/output/client [client.cpp]
set -euo pipefail

fixture="$(cd "$(dirname "$0")" && pwd)"
build="$(cd "$1" && pwd)"
deps="$build/_deps"
source="$(sed -n 's/^CMAKE_HOME_DIRECTORY:INTERNAL=//p' "$build/CMakeCache.txt")"
ssl="$(sed -n 's/^OPENSSL_SSL_LIBRARY:FILEPATH=//p' "$build/CMakeCache.txt")"
crypto="$(sed -n 's/^OPENSSL_CRYPTO_LIBRARY:FILEPATH=//p' "$build/CMakeCache.txt")"

"${CXX:-c++}" -std=c++20 -O2 -pthread \
    -I"$source/Falcon.Network/include" \
    -I"$source/Falcon.Protocol/include" \
    -I"$source/Falcon.Shared/include" \
    "${3:-$fixture/client.cpp}" -o "$2" \
    "$build/Falcon.Network/libFalconNetwork.a" \
    "$build/Falcon.Protocol/libFalconProtocol.a" \
    "$build/Falcon.Shared/libFalconShared.a" \
    "$deps/snappy-build/libsnappy.a" \
    "$deps/libdatachannel-build/libdatachannel-static.a" \
    "$deps/libdatachannel-build/deps/usrsctp/usrsctplib/libusrsctp.a" \
    "$deps/libdatachannel-build/deps/libjuice/libjuice-static.a" \
    "$build/Falcon.Server/libFalconTransportTranscript.a" \
    "$ssl" "$crypto" -lz
