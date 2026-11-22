#!/usr/bin/env bash
# Starts a Falcon server with the built plugin in a scratch folder, stops it, and checks the self test passed.
# Usage: scripts/smoke-test.sh <FalconServer binary> <folder holding the built plugin>
set -euo pipefail

server="$1"
built="$2"
work="$(mktemp -d)"
mkdir -p "$work/plugins/pmmp-compat"
cp -R "$built"/. "$work/plugins/pmmp-compat/"
printf 'server-port=19142\nserver-portv6=19143\nonline-mode=false\n' > "$work/server.properties"

# the plugins are enabled before the console is read, so a stop waiting on stdin ends the run
(cd "$work" && printf 'stop\n' | "$server" --no-wizard > server.log 2>&1) || true
cat "$work/server.log"

grep -q "\[pmmp-compat\] PHP .* (thread-safe) started" "$work/server.log"
grep -q "\[pmmp-compat\] 18 of 18 checks passed" "$work/server.log"
grep -q "\[pmmp-compat\] PHP still runs after the fatal error" "$work/server.log"
grep -q "\[pmmp-compat\] PHP stopped" "$work/server.log"
echo "smoke test passed"
