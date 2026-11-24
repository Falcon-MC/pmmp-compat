#!/usr/bin/env bash
# Usage: bash run.sh <FalconServer> <installed pmmp-compat directory> <original EasyEdit 3.1.0 PHAR>
set -euo pipefail

fixture="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$fixture/../../.." && pwd)"
work="$(mktemp -d)"
plugin="$work/plugins/pmmp-compat"
mkdir -p "$plugin/plugins/EditProbe" "$plugin/plugin_data/EasyEdit"
cp -R "$2"/. "$plugin/"
cp -R "$fixture/src" "$fixture/plugin.yml" "$plugin/plugins/EditProbe/"
php_cli="${PHP:-php}"
"$php_cli" -d phar.readonly=0 "$root/scripts/patch-easyedit.php" "$3" "$plugin/plugins/EasyEdit.phar"
"$php_cli" -r 'echo (new Phar($argv[1]))["resources/config.yml"]->getContent();' "$3" \
    | sed -e 's/^download-data: true$/download-data: false/' \
        -e 's/^terrain-ignored-blocks:.*/terrain-ignored-blocks: ["missing_probe_tag"]/' \
        > "$plugin/plugin_data/EasyEdit/config.yml"
port="${PMMP_COMPAT_TEST_PORT:-19580}"
printf 'server-port=%s\nserver-portv6=%s\nonline-mode=false\n' "$port" "$((port + 1))" > "$work/server.properties"
echo "Logs are in $work"
log="$work/captured.log"
if ! "${PYTHON:-python3}" "$root/scripts/run-test-server.py" "$1" "$work" "$log" --seconds 8 \
    || ! grep -F 'Terrain snapshot and rejection checks passed' "$log" \
    || ! grep -F 'EasyEdit selection and fill passed' "$log" \
    || ! grep -F 'EasyEdit undo passed' "$log" \
    || grep -Fq 'must not be accessed before initialization' "$log"; then
    cat "$log"
    exit 1
fi
