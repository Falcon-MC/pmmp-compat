#!/usr/bin/env bash
# Usage: lifecycle-plugin-test.sh FalconServer pmmp-compat-dir StarterKit.phar ScoreHud.phar
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
server="$1"
built="$2"
starterkit="$3"
scorehud="$4"
work="$(mktemp -d)"
plugin="$work/plugins/pmmp-compat"
fixture="$plugin/plugins/PluginLifecycle"
mkdir -p "$fixture"
cp -R "$built"/. "$plugin/"
cp -R "$root/tests/integration/PluginLifecycle"/. "$fixture/"
cp "$starterkit" "$scorehud" "$plugin/plugins/"
printf 'server-port=19542\nserver-portv6=19543\nonline-mode=false\n' > "$work/server.properties"
echo "Logs are in $work"

for run in first restart; do
    log="$work/$run.log"
    python3 "$root/scripts/run-test-server.py" "$server" "$work" "$log"
    if [[ "$run" == first ]]; then
        grep -F "fixture: StarterKit granted the first-join kit" "$log"
    else
        grep -F "fixture: StarterKit did not grant a second kit" "$log"
    fi
    for action in 'displayed the objective' 'updated a score line' 'removed a score line' 'removed the objective'; do
        grep -Fq "fixture: ScoreHud $action" "$log"
    done
    if grep -Eq "Could not (load|enable) plugin '(StarterKit|ScoreHud|PluginLifecycle)'|of (StarterKit|ScoreHud|PluginLifecycle) v.* failed|from (StarterKit|ScoreHud|PluginLifecycle) failed" "$log"; then
        echo "A plugin failed during $run. See $log." >&2
        exit 1
    fi
    grep -Fq 'Server stopped.' "$log"
done
