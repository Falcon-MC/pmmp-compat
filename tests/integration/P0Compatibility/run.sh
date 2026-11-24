#!/usr/bin/env bash
# Usage: bash run.sh /path/to/FalconServer /path/to/installed/pmmp-compat
set -euo pipefail

fixture="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$fixture/../../.." && pwd)"
work="$(mktemp -d)"
port="${PMMP_COMPAT_TEST_PORT:-19700}"
plugin="$work/plugins/pmmp-compat"
mkdir -p "$plugin/plugins/P0Compatibility"
cp -R "$2"/. "$plugin/"
cp -R "$fixture/src" "$fixture/plugin.yml" "$plugin/plugins/P0Compatibility/"
cat >"$work/server.properties" <<EOF
server-name=P0 Compatibility Fixture
max-players=19
server-port=$port
server-portv6=$((port + 1))
online-mode=false
gamemode=creative
force-gamemode=true
view-distance=11
level-seed=1896762365222302013
EOF

echo "Logs are in $work"
log="$work/captured.log"
native_items="${PMMP_COMPAT_TEST_NATIVE_ITEM_ENTITY:-1}"
export PMMP_COMPAT_TEST_NATIVE_ITEM_ENTITY="$native_items"
if ! "${PYTHON:-python3}" "$root/scripts/run-test-server.py" "$1" "$work" "$log" --seconds 5 ||
   ! grep -F '[P0Compatibility] server and world compatibility passed' "$log" ||
   ! grep -F '[P0Compatibility] player state compatibility passed' "$log" ||
   { [[ "$native_items" == 1 ]] && { ! grep -F '[P0Compatibility] native dropped item lifecycle passed' "$log" ||
       ! grep -F '[P0Compatibility] native block damage and combustion passed' "$log"; }; }; then
    cat "$log"
    exit 1
fi
