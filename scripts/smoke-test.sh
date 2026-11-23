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
port="${PMMP_COMPAT_TEST_PORT:-19142}"
printf 'server-port=%s\nserver-portv6=%s\nonline-mode=false\n' "$port" "$((port + 1))" > "$work/server.properties"

python=python3
if ! "$python" -c "" 2>/dev/null; then
    python=python
fi
for phar in "$root"/tests/phar/*/; do
    name="$(basename "$phar")"
    "$python" "$root/scripts/make-phar.py" "$phar" "$plugin/plugins/$name.phar"
done

log="$work/captured.log"
failed=0
"$python" "$root/scripts/run-test-server.py" "$server" "$work" "$log" \
    --command 'blocked thing' --command 'greet from stdin' || failed=1
cat "$log"

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
expect "fixture: Alpha PluginManager dispatch and permission registration work"
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
expect "17 PocketMine-MP plugins loaded, 9 could not be"

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

# a simulated player meets the Greeter plugin: events at their priorities, commands, chat, the leave reason
expect "fixture: Greeter listens, greet is registered"
expect "fixture: Greeter saw Visitor join with \"§eVisitor joined the game\""
expect "fixture: Greeter finds the same player, 1 online"
expect "fixture: Greeter permissions: greet yes, secret no"
expect "fixture: Greeter monitor sees \"§aWelcome Visitor\""
expect "fixture: Greeter greeted Visitor as /greet hello world"
expect "fixture: Greeter heard \"said through Player::chat\""
expect "[Visitor] hi everyone!"
expect "fixture: Greeter saw the cancelled \"a secret plan\""
expect "fixture: Greeter greeted CONSOLE as /greet from the console"
expect "Hello from the console"
expect "fixture: Greeter saw Visitor leave: Bye from the test"
for refused in "Greeter ran secret for Visitor" "[Visitor] a secret plan"; do
    if grep -qF "$refused" "$log"; then
        echo "should not be in the log: $refused"
        failed=1
    fi
done

# the scheduler, async tasks, utilities and the command and game mode events
expect "fixture: Ticker singleton kept"
expect "fixture: Ticker random repeats"
expect "fixture: Ticker binary round trips"
expect "fixture: Ticker curl ready for PocketMine-MP 5.44.3"
expect "fixture: Ticker async progress halfway"
expect "fixture: Ticker async result 6"
expect "fixture: Ticker async error reported"
expect "a task that fails on purpose"
expect "fixture: Ticker delayed task ran at tick 5"
expect "fixture: Ticker repeating task stopped after 3 runs"
expect "fixture: Ticker saw Visitor ask for CREATIVE"
expect "fixture: Ticker saw Visitor ask for ADVENTURE"
expect "fixture: Ticker sees Visitor in CREATIVE"
expect "fixture: Ticker saw command \"blocked thing\" from CONSOLE"
expect "fixture: Ticker saw command \"greet from stdin\" from CONSOLE"
expect "fixture: Greeter greeted CONSOLE as /greet from stdin"
if grep -qF "Visitor in ADVENTURE" "$log"; then
    echo "a cancelled game mode change went through"
    failed=1
fi

# worlds, blocks, items, a player's inventory and body, and the events that carry them
expect "fixture: Explorer default world found by name, 3 levels"
expect "fixture: Explorer placed Gold Block, read back at y 250"
expect "fixture: Explorer cleared it to Air"
expect "fixture: Explorer time of day 1000"
expect "fixture: Explorer parsed the same sword"
expect "fixture: Explorer json keeps Blade with sharp"
expect "fixture: Explorer refused an unknown item"
expect "fixture: Explorer gave it, slot 0 holds Blade (sharp)"
expect "fixture: Explorer sees Visitor in Bedrock level with 20 health, found by id"
expect "fixture: Explorer saw Visitor teleport 3 blocks"
expect "fixture: Explorer saw Visitor take 4 damage, cause 14"
expect "fixture: Explorer sees Visitor at 19 health"
expect "fixture: Explorer saw Visitor die"

# packets, forms, a player's connection and the entity register
expect "fixture: Courier registered its npc as courier:npc"
expect "fixture: Courier packet round trips, protocol 1.26.60, pool ready"
expect "fixture: Courier score, block actor NBT and fake actor packets round trip"
expect "fixture: Courier sees Visitor with uuid set and protocol known"
expect "fixture: Courier sent a form and a packet: sent"

# the parameters a client is shown for plugin commands: Falcon's list as a Commando hook and an editing plugin change it
expect "fixture: Commander registered shop and its alias in Falcon"
expect "fixture: Commander decoded the captured command list: hidden, note, ping, shop"
expect "fixture: Commander shop (shop, store): <buy: buy> <item: apple|bread> [amount: int] / <sell: sell> <item: soft ShopItems> / [player: target]"
expect "fixture: Commander note: [args: text]"
expect "fixture: Commander ping: <host: string> [count: int x]"
expect "fixture: Commander hidden gone, soft enums ShopItems: apple, bread, cake"
expect "fixture: Commander saw 1 list go out"

# enchantments, the inventories a player wears and keeps, and a menu window as InvMenu opens one
expect "fixture: Shopkeeper sword keeps sharpness 3, parser agrees"
expect "fixture: Shopkeeper chest network id known"
expect "fixture: Shopkeeper dressed Visitor: Diamond Helmet, 16 arrows, 3 gold in the ender chest"
expect "fixture: Shopkeeper saw Menu open"
expect "fixture: Shopkeeper menu opened"
expect "fixture: Shopkeeper menu holds 2 emeralds before closing"
expect "fixture: Shopkeeper saw Menu close"

# the rest of the world: typed items and blocks, tiles, effects, hunger, experience, sounds, drops and the server
expect "fixture: Wanderer 15 of 15 checks passed"
expect "fixture: Wanderer saw a chunk load in"
expect "fixture: Wanderer saw Visitor get effect potion.moveSpeed 2"
expect "fixture: Wanderer gave speed: running"
expect "fixture: Wanderer took speed away: gone"
expect "fixture: Wanderer food 15 of 20"
expect "fixture: Wanderer xp level 3"
expect "fixture: Wanderer skin known"
expect "fixture: Wanderer played a sound and a particle"
expect "fixture: Wanderer death drops"

# entities a plugin defines: PHP runs and draws them, a Falcon stand-in takes hits
expect "fixture: Menagerie registered Dummy"
expect "fixture: Menagerie Dummy ticked on its own"
expect "fixture: Menagerie Dummy was hurt by Falcon's explosion"
expect "fixture: Menagerie 14 of 14 checks passed"
expect "fixture: Wanderer kept Blade after death"

# databases, threads, the server's sleeper and promises
expect "fixture: Keeper sqlite3 reads 100"
expect "fixture: Keeper pdo counts 1, drivers mysql,sqlite"
expect "fixture: Keeper mysqli ready, bcmath 0.3, igbinary 1, sockets ready"
expect "fixture: Keeper yaml reads 0, 8, '010'"
if [[ "$server" == *.exe ]] && grep -Fq "fixture: Builder zip extension unavailable on Windows" "$log"; then
    expect "fixture: Keeper zip reads unavailable on Windows, leveldb reads terrain, jsonmapper maps Steve, json-comment reads 1"
else
    expect 'fixture: Keeper zip reads {"format_version": 2}, leveldb reads terrain, jsonmapper maps Steve, json-comment reads 1'
fi
expect "fixture: Keeper promise resolved with gold"
expect "fixture: Keeper thread woke the server"
expect "fixture: Keeper thread squared the sum: 100"
expect "fixture: Keeper legacy notifier squared the sum: 9"
expect "fixture: Keeper worker wrote sqlite, total 142"

# what plugins build with: item and block types, saved items, enums, generators and the server's network
expect "fixture: Builder 24 of 24 checks passed"

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
