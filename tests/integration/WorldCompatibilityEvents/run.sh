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
printf 'server-port=19568\nserver-portv6=19569\nonline-mode=false\nenable-query=true\nquery-ip=127.0.0.1\nquery-port=19570\n' >"$work/server.properties"
echo "Logs are in $work"
log="$work/captured.log"
python="${PYTHON:-python3}"
if [[ -z "${PYTHON:-}" ]] && ! "$python" -c "" 2>/dev/null; then
    python=python
fi
"$python" "$root/scripts/run-test-server.py" "$1" "$work" "$log" --seconds 6 &
server=$!
query_status=0
"$python" "$fixture/query.py" 19570 || query_status=$?
if ! wait "$server" ||
   [[ "$query_status" -ne 0 ]] ||
   ! grep -F '[WorldCompatibilityEvents] world compatibility events passed' "$log" ||
   grep -Fq 'Could not pass an event to the PocketMine-MP plugins' "$log" ||
   { [[ -n "${PMMP_COMPAT_TEST_UPDATE_TAG:-}" ]] &&
     ! grep -F "Falcon update notification delivered: $PMMP_COMPAT_TEST_UPDATE_TAG" "$log"; }; then
    cat "$log"
    exit 1
fi
