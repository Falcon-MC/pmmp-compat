#!/usr/bin/env bash
# Usage: bash run.sh <FalconServer> <installed pmmp-compat directory> <WarpGUI PHAR>
set -euo pipefail

fixture="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$fixture/../../.." && pwd)"
work="$(mktemp -d)"
port="${PMMP_COMPAT_TEST_PORT:-19760}"
shim="$work/plugins/pmmp-compat"
mkdir -p "$shim/plugins/CommandoWarpGui"
cp -R "$2/." "$shim/"
cp -R "$fixture/src" "$fixture/plugin.yml" "$shim/plugins/CommandoWarpGui/"
cp "$3" "$shim/plugins/WarpGUI.phar"
printf 'server-port=%s\nserver-portv6=%s\nonline-mode=false\n' "$port" "$((port + 1))" > "$work/server.properties"
echo "Logs are in $work"
log="$work/captured.log"
if ! "${PYTHON:-python3}" "$root/scripts/run-test-server.py" "$1" "$work" "$log" --seconds 3 ||
    ! grep -F '[CommandoWarpGui] WarpGUI Commando alias, optional and multiword arguments passed' "$log"; then
    cat "$log"
    exit 1
fi
