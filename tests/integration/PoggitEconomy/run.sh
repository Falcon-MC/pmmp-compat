#!/usr/bin/env bash
# Usage: bash run.sh <FalconServer> <installed pmmp-compat directory> <archive directory> [chest client]
set -euo pipefail

fixture="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$fixture/../../.." && pwd)"
server="$1"
shim="$2"
archives="$3"
chest_client="${4:-}"
php_cli="${PHP:-php}"
port="${PMMP_COMPAT_TEST_PORT:-19600}"
work="$(mktemp -d)"
echo "Logs are in $work"

setup() {
    local profile="$1" game_port="$2"
    local plugin="$work/$profile/plugins/pmmp-compat"
    mkdir -p "$plugin/plugins/EconomyProbe"
    cp -R "$shim/." "$plugin/"
    cp -R "$fixture/src" "$fixture/plugin.yml" "$plugin/plugins/EconomyProbe/"
    printf 'server-port=%s\nserver-portv6=%s\nonline-mode=false\n' "$game_port" "$((game_port + 1))" > "$work/$profile/server.properties"
}

run_profile() {
    local profile="$1"
    shift
    local log="$work/$profile/captured.log"
    local options=(--seconds 7)
    if [[ "$profile" = chest && -n "$chest_client" ]]; then
        options=(--seconds 30 --client "$chest_client" --client-port "$((port + 2))")
    fi
    if ! "${PYTHON:-python3}" "$root/scripts/run-test-server.py" "$server" "$work/$profile" "$log" "${options[@]}"; then
        cat "$log"
        exit 1
    fi
    for tested_plugin in "$@"; do
        if ! grep -F "[EconomyProbe] $tested_plugin transaction passed" "$log"; then
            cat "$log"
            exit 1
        fi
    done
}

setup bedrock "$port"
bedrock="$work/bedrock/plugins/pmmp-compat/plugins"
"$php_cli" -d phar.readonly=0 "$root/scripts/patch-bedrockeconomy.php" "$archives/BedrockEconomy_4.0.4.phar" "$bedrock/BedrockEconomy.phar"
for archive in ChatGames_1.1.0 RankShopGUI_1.0.0 RankSystem_1.1.4 XPShop_2.1.0; do
    cp "$archives/$archive.phar" "$bedrock/"
done
run_profile bedrock ChatGames XPShop RankShopGUI

setup chest "$((port + 2))"
chest="$work/chest/plugins/pmmp-compat/plugins"
cp -R "$archives/EconomyAPI" "$chest/"
cp "$archives/ChestShop_5.3.9.phar" "$chest/"
if [[ -n "$chest_client" ]]; then
    printf 'transport=nethernet\nallow-unofficial-clients=true\n' >> "$work/chest/server.properties"
    PMMP_COMPAT_CHESTSHOP_WIRE=1 run_profile chest ChestShop
else
    run_profile chest ChestShop
fi

setup bank "$((port + 4))"
bank="$work/bank/plugins/pmmp-compat/plugins"
cp -R "$archives/EconomyAPI" "$bank/"
cp "$archives/BankGUI_1.0.0.phar" "$bank/"
run_profile bank BankGUI
