#!/usr/bin/env bash
# Starts a Falcon server with the built plugin and the test plugins in a scratch folder, stops it, and checks the log.
# Usage: scripts/smoke-test.sh <FalconServer binary> <folder holding the built plugin>
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
server="$1"
built="$2"
work="$(mktemp -d)"
plugin="$work/plugins/pmmp-compat"
mkdir -p "$plugin/plugins"
cp -R "$built"/. "$plugin/"
cp -R "$root/tests/plugins"/. "$plugin/plugins/"
printf 'server-port=19142\nserver-portv6=19143\nonline-mode=false\n' > "$work/server.properties"

python=python3
if ! "$python" -c "" 2>/dev/null; then
    python=python
fi
for phar in "$root"/tests/phar/*/; do
    name="$(basename "$phar")"
    "$python" "$root/scripts/make-phar.py" "$phar" "$plugin/plugins/$name.phar"
done

# the plugins are enabled before the console is read, so a stop waiting on stdin ends the run
(cd "$work" && printf 'stop\n' | PMMP_COMPAT_SELFTEST=1 "$server" --no-wizard > server.log 2>&1) || true
log="$work/server.log"
cat "$log"

failed=0
expect() {
    if ! grep -qF -- "$1" "$log"; then
        echo "missing from the log: $1"
        failed=1
    fi
}
# the first line holding the text, 0 when none does
line_of() {
    grep -nF -- "$1" "$log" | head -n 1 | cut -d: -f1 || true
}
expect_before() {
    local first second
    first="$(line_of "$1")"
    second="$(line_of "$2")"
    if [ -z "$first" ] || [ -z "$second" ] || [ "$first" -ge "$second" ]; then
        echo "expected \"$1\" before \"$2\""
        failed=1
    fi
}

# the interpreter
grep -q "\[pmmp-compat\] PHP .* (thread-safe) started" "$log" || { echo "PHP did not start"; failed=1; }
expect "18 of 18 checks passed"
expect "PHP still runs after the fatal error"

# what loads, in the order its dependencies need
expect "fixture: Beta loaded"
expect "fixture: Beta enabled, data folder made"
expect "fixture: Alpha enabled, Alpha's greeter says hello from Beta"
expect "fixture: Gamma enabled"
expect "fixture: Dupe enabled"
expect_before "fixture: Beta enabled" "fixture: Gamma enabled"
expect_before "fixture: Gamma enabled" "fixture: Alpha enabled"

# what is refused, and why
expect "Could not load plugin 'OldApi': Incompatible API version (plugin requires one of: 4.0.0)"
expect "Could not load plugin 'Lonely': Unknown dependency: Nowhere"
expect "Could not load plugin 'CycleA': Circular dependency detected"
expect "Could not load plugin 'CycleB': Circular dependency detected"
expect "Could not load plugin 'Dupe': plugin exists"
expect "Could not load plugin 'NeedsMongo': Required PHP extension 'mongodb' is not loaded"
expect "Could not load plugin 'NotAPlugin': Main class is not an instance of pocketmine\\plugin\\Plugin"
expect "Intruder': Invalid plugin manifest: Invalid Plugin main, cannot start within the PocketMine namespace"
expect "Could not enable plugin 'Crashing': boom"
expect "fixture: Crashing cleaned up"
expect "Could not enable plugin 'Quitter': Plugin disabled itself when attempting to enable it"
expect "Could not load plugin 'FatalLoad': a fatal error or exit() stopped it"
expect "fixture: FatalEnable enabling"
expect "Could not enable plugin 'FatalEnable': a fatal error or exit() stopped it"
expect "Could not enable plugin 'Exiter': a fatal error or exit() stopped it"
expect "7 PocketMine-MP plugins loaded, 9 could not be"

# the plugin base: logger, config.yml, resources, Config, TextFormat
expect "[Basics] logger says hello"
expect "[Basics] logger gives notice"
expect "[Basics] logger warns"
expect "[Basics] logger errs"
expect "an exception logged on purpose"
expect "fixture: Basics 27 of 27 checks passed"
if grep -qF "logger debug stays hidden" "$log"; then
    echo "a debug message reached the log"
    failed=1
fi

# a plugin stops before the one it depends on
expect "fixture: Alpha disabled"
expect "fixture: Beta disabled"
expect_before "fixture: Alpha disabled" "fixture: Beta disabled"
expect "[pmmp-compat] PHP stopped"

if [ "$failed" -ne 0 ]; then
    echo "smoke test failed"
    exit 1
fi
echo "smoke test passed"
