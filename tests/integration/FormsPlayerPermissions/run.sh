#!/usr/bin/env bash
# Usage: bash run.sh /absolute/path/to/FalconServer /path/to/installed/pmmp-compat
set -euo pipefail

fixture="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$fixture/../../.." && pwd)"
work="$(mktemp -d)"
plugin="$work/plugins/pmmp-compat"
mkdir -p "$plugin/plugins/FormsPlayerPermissions"
cp -R "$2"/. "$plugin/"
cp -R "$fixture/src" "$fixture/plugin.yml" "$plugin/plugins/FormsPlayerPermissions/"
printf 'server-port=19442\nserver-portv6=19443\nonline-mode=false\n' > "$work/server.properties"
cat > "$work/permission-groups.json" <<'JSON'
{"default":"fixture","groups":{"fixture":{"inherits":[],"permissions":{"fixture.group":true,"fixture.denied":false}}},"players":{}}
JSON
echo "Logs are in $work"
python="${PYTHON:-python3}"
if [[ -z "${PYTHON:-}" ]] && ! "$python" -c "" 2>/dev/null; then
    python=python
fi
log="$work/captured.log"
if ! "$python" "$root/scripts/run-test-server.py" "$1" "$work" "$log" ||
    ! grep -F '[FormsPlayerPermissions] player compatibility online passed' "$log" ||
    ! grep -F '[FormsPlayerPermissions] player compatibility offline passed' "$log"; then
    cat "$log"
    exit 1
fi
