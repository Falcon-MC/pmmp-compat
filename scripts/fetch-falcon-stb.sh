#!/usr/bin/env bash
# Fall back to the same pinned header if the raw GitHub endpoint is rate-limited.
set -euo pipefail

source_file="$1/CMakeLists.txt"
output="$2"
pin="$(sed -nE 's@^[[:space:]]*URL https://raw.githubusercontent.com/nothings/stb/([a-f0-9]+)/stb_image.h$@\1@p' "$source_file")"
checksum="$(sed -nE '/URL https:\/\/raw.githubusercontent.com\/nothings\/stb\//{n;s/^[[:space:]]*URL_HASH SHA256=([a-f0-9]+)$/\1/p;}' "$source_file")"
[[ "$pin" =~ ^[a-f0-9]{40}$ && "$checksum" =~ ^[a-f0-9]{64}$ ]]

mkdir -p "$output"
curl --fail --silent --show-error --location --retry 3 \
    "https://cdn.jsdelivr.net/gh/nothings/stb@$pin/stb_image.h" -o "$output/stb_image.h"
cmake -E sha256sum "$output/stb_image.h" | awk -v want="$checksum" '$1 == want {found=1} END {exit !found}'
