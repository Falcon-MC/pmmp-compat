#!/usr/bin/env bash
# Usage: run.sh <FalconServer> <pmmp-compat install> <PluginAPI checkout> <Poggit release archive> <EconomyAPI folder> [profile]
set -euo pipefail

fixture="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$fixture/../../.." && pwd)"
work="$(mktemp -d)"
echo "Logs are in $work"
port="${PMMP_COMPAT_TEST_PORT:-19790}"
case "$(uname)" in
    Darwin) shared=(-dynamiclib); library=libRoadmapProbe.dylib ;;
    Linux) shared=(-shared -fPIC); library=libRoadmapProbe.so ;;
    *) echo "Build the RoadmapProbe fixture on macOS or Linux" >&2; exit 2 ;;
esac

"${CC:-cc}" "${shared[@]}" -I "$3/native/include" "$fixture/probe.c" -o "$work/$library"
server="$1"
install="$2"
archives="$4"
economy="$5"

setup() {
    local profile="$1"
    shift
    run="$work/$profile"
    shim="$run/plugins/pmmp-compat"
    mkdir -p "$shim/plugins/PoggitRoadmap" "$run/plugins/RoadmapProbe"
    cp -R "$install/." "$shim/"
    cp -R "$fixture/src" "$fixture/plugin.yml" "$shim/plugins/PoggitRoadmap/"
    cp "$work/$library" "$run/plugins/RoadmapProbe/"
    cp "$fixture/native-plugin.json" "$run/plugins/RoadmapProbe/plugin.json"
    for archive in "$@"; do
        cp "$archives/$archive" "$shim/plugins/"
    done
    printf 'server-port=%s\nserver-portv6=%s\ntransport=nethernet\nonline-mode=false\nallow-unofficial-clients=true\nview-distance=6\n' "$port" "$((port + 1))" > "$run/server.properties"
}

exercise() {
    local log="$run/captured.log"
    if [[ -n "${ROADMAP_CLIENT:-}" && ("$profile" == inventory || "$profile" == slapper || "$profile" == shop) ]]; then
        ROADMAP_REAL_CLIENT=1 "${PYTHON:-python3}" "$root/scripts/run-test-server.py" "$server" "$run" "$log" --seconds 15 &
        local server_pid=$!
        local deadline=$((SECONDS + 45))
        while ! grep -qF 'Server started.' "$log" 2>/dev/null; do
            if ! kill -0 "$server_pid" 2>/dev/null || [[ "$SECONDS" -ge "$deadline" ]]; then
                wait "$server_pid" || true
                cat "$log"
                return 1
            fi
            sleep .1
        done
        local client_result=0
        "$ROADMAP_CLIENT" "$port" "$profile" || client_result=$?
        wait "$server_pid" || return 1
        [[ "$client_result" -eq 0 ]] || return 1
    elif ! "${PYTHON:-python3}" "$root/scripts/run-test-server.py" "$server" "$run" "$log" --seconds 8; then
        cat "$log"
        return 1
    fi
    local marker
    for marker in 'Native-to-PHP service passed' 'PluginOwned and PHP-to-native service passed' 'Disabled plugin' "$@"; do
        if ! grep -F "$marker" "$log"; then
            cat "$log"
            return 1
        fi
    done
}

shop_data() {
    local data="$shim/plugin_data/CustomShopUI"
    mkdir -p "$data"
    cp -R "$economy" "$shim/plugins/EconomyAPI"
    cat > "$data/config.yml" <<'CONFIG'
config-version: 6
economy:
  plugin: EconomyAPI
Default-Shop: Shop
Titles:
  Default: Fixture Shop
Thanks: false
Survival: false
Category_ExitButton: false
Items_ExitButton: false
Types:
  Toggle: true
  Input: true
  Slider: false
  StepSlider: false
CONFIG
    cat > "$data/shop.yml" <<'SHOP'
shop-version: 5
shop:
  0:
    Name: "Produce:textures/items/apple"
    Items:
      - "260:0:1:10:0:Default:textures/items/apple"
SHOP
}

profiles=("${6:-all}")
if [[ "${profiles[0]}" == all ]]; then
    profiles=(builder worlds shop skyblock slapper visibility inventory)
fi
result=0
for profile in "${profiles[@]}"; do
    case "$profile" in
        builder)
            setup "$profile" CzechPMDevs/BuilderTools/2.0.0-beta1/BuilderTools_2.0.0-beta1.phar
            exercise 'BuilderTools 1024-block edit, undo and async schematic passed' || result=1 ;;
        worlds)
            setup "$profile" CzechPMDevs/MultiWorld/2.1.2/MultiWorld_2.1.2.phar survanetwork/Worlds/4.0.9/Worlds_4.0.9.phar
            exercise 'MultiWorld and Worlds generate, teleport and reload passed' || result=1 ;;
        shop)
            setup "$profile" AlexPads/CustomShopUI/5.0.5/CustomShopUI_5.0.5.phar
            shop_data
            exercise 'CustomShopUI category, item and purchase forms passed' || result=1 ;;
        skyblock)
            setup "$profile" dresnite/SkyBlock/4.0.2/SkyBlock_4.0.2.phar
            exercise 'SkyBlock generated and joined the Shelly island' || result=1 ;;
        slapper)
            setup "$profile" vecnavium-pm-pl/Slapper/3.0.0/Slapper_3.0.0.phar
            exercise 'Slapper NPC, hit command and NBT passed' || result=1 ;;
        visibility)
            setup "$profile" sqrrrrl/VanishV2/4.0.4/VanishV2_4.0.4.phar
            exercise 'VanishV2 and native player visibility passed' || result=1 ;;
        inventory)
            setup "$profile" BlockHorizons/InvSee/2.4.3/InvSee_2.4.3.phar
            exercise 'InvSee player and ender inventory passed' || result=1 ;;
        *) echo "Unknown profile $profile" >&2; exit 2 ;;
    esac
    port=$((port + 2))
done
exit "$result"
