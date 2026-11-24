#!/usr/bin/env bash
# Usage: bash run.sh <FalconServer> <installed pmmp-compat directory> <Poggit archive root>
set -euo pipefail

fixture="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$fixture/../../.." && pwd)"
work="$(mktemp -d)"
port="${PMMP_COMPAT_TEST_PORT:-19752}"
shim="$work/plugins/pmmp-compat"
mkdir -p "$shim/plugins/PoggitCustomEntities"
cp -R "$2/." "$shim/"
cp -R "$fixture/src" "$fixture/plugin.yml" "$shim/plugins/PoggitCustomEntities/"
for archive in \
    BeeAZ-pm-pl/HumanNPC/0.1.2/HumanNPC_0.1.2.phar \
    diamond-gold/MiniBosses/3.4.1/MiniBosses_3.4.1.phar \
    BlockHorizons/BlockPets/3.0.0/BlockPets_3.0.0.phar; do
    cp "$3/$archive" "$shim/plugins/"
done
printf 'server-port=%s\nserver-portv6=%s\nonline-mode=false\n' "$port" "$((port + 1))" > "$work/server.properties"
echo "Logs are in $work"
log="$work/captured.log"
if ! "${PYTHON:-python3}" "$root/scripts/run-test-server.py" "$1" "$work" "$log" --seconds 7 ||
    ! grep -F '[PoggitCustomEntities] HumanNPC spawn, skin, hit command and NBT passed' "$log" ||
    ! grep -F '[PoggitCustomEntities] MiniBosses create, spawn, damage and despawn passed' "$log" ||
    ! grep -F '[PoggitCustomEntities] BlockPets spawn, scale, ticks and removal passed' "$log"; then
    cat "$log"
    exit 1
fi
