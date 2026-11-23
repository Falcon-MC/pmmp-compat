#!/usr/bin/env bash
# Usage: bash run.sh /absolute/path/to/FalconServer /path/to/installed/pmmp-compat
set -euo pipefail

fixture="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$fixture/../../.." && pwd)"
work="$(mktemp -d)"
plugin="$work/plugins/pmmp-compat"
mkdir -p "$plugin/plugins/WorldCompatibilityEvents"
cp -R "$2"/. "$plugin/"
cp -R "$fixture/src" "$fixture/plugin.yml" "$plugin/plugins/WorldCompatibilityEvents/"
printf 'server-port=19568\nserver-portv6=19569\nonline-mode=false\n' >"$work/server.properties"
echo "Logs are in $work"
log="$work/captured.log"
if ! "${PYTHON:-python3}" "$root/scripts/run-test-server.py" "$1" "$work" "$log" --seconds 5 ||
   ! grep -F '[WorldCompatibilityEvents] world compatibility events passed' "$log"; then
    cat "$log"
    exit 1
fi
