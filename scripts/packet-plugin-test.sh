#!/usr/bin/env bash
# Usage: packet-plugin-test.sh FalconServer pmmp-compat-dir InvMenu-src ScoreHud.phar Texter.phar
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
server="$1"
built="$2"
invmenu="$3"
scorehud="$4"
texter="$5"
work="$(mktemp -d)"
echo "Logs are in $work"

for index in 0 1 2; do
    run="$work/menu-$index"
    plugin="$run/plugins/pmmp-compat"
    fixture="$plugin/plugins/PacketPlugins"
    mkdir -p "$fixture"
    cp -R "$built"/. "$plugin/"
    cp -R "$root/tests/integration/PacketPlugins"/. "$fixture/"
    cp -R "$invmenu/muqsit" "$fixture/src/"
    cp "$scorehud" "$texter" "$plugin/plugins/"
    port=$((19342 + 2 * index))
    printf 'server-port=%s\nserver-portv6=%s\nonline-mode=false\n' "$port" "$((port + 1))" > "$run/server.properties"
    STEP11_MENU="$index" python3 "$root/scripts/run-test-server.py" "$server" "$run" "$run/captured.log" &
    pids[$index]=$!
done

sizes=(27 54 5)
for index in 0 1 2; do
    wait "${pids[$index]}"
    log="$work/menu-$index/captured.log"
    size="${sizes[$index]}"
    grep -F "fixture passed $size" "$log"
    test "$(grep -Fc "fixture close $size" "$log")" -eq 1
    for packet in SetDisplayObjectivePacket SetScorePacket RemoveObjectivePacket UpdateBlockPacket BlockActorDataPacket AddActorPacket SetActorDataPacket RemoveActorPacket; do
        grep -Fq "fixture wire $packet" "$log"
    done
    grep -Fq "Server stopped." "$log"
done
