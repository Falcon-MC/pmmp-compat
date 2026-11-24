<p align="center">
	<picture>
		<source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/Falcon-MC/Falcon/main/.github/logo-white.png">
		<img src="https://raw.githubusercontent.com/Falcon-MC/Falcon/main/.github/logo.png" alt="Falcon" width="200">
	</picture>
	<br>
	<b>Falcon PocketMine Compatibility</b>
	<br>
	Runs PocketMine-MP plugins on the <a href="https://github.com/Falcon-MC/Falcon">Falcon</a> Minecraft: Bedrock Edition server
</p>

<p align="center">
	<img src="https://img.shields.io/badge/pocketmine%20api-5.x-blue" alt="PocketMine API 5">
	<img src="https://img.shields.io/badge/language-C%2B%2B17%20%7C%20PHP%208-00599C" alt="Languages">
	<img src="https://img.shields.io/badge/platform-Windows%20%7C%20Linux%20%7C%20macOS-lightgrey" alt="Platform">
</p>

## What is this?

A Falcon plugin that loads PocketMine-MP plugins as they are, from their `.phar` file or source folder. A
server can move to Falcon and keep its plugins without rewriting them.

It embeds the PHP interpreter and implements the public PocketMine-MP API (API 5) on top of the
[Falcon Plugin API](https://github.com/Falcon-MC/PluginAPI). PocketMine plugins call `pocketmine\...` classes as
usual, and each class forwards to Falcon.

- **Loader**:
  - reads `plugin.yml`, the `.phar` or source folder, and the `depend`, `softdepend` and `loadbefore` lists;
  - enables plugins in the order PocketMine-MP uses.
- **Plugin base**:
  - `PluginBase`, `onLoad`, `onEnable` and `onDisable`;
  - the data folder and `Config` with YAML and JSON;
  - the plugin's logger.
- **Events**:
  - Falcon events go to PocketMine listeners, with priorities, cancellation and `@handleCancelled`.
- **Commands**:
  - commands declared in `plugin.yml` or registered at run time;
  - `onCommand`, permissions and aliases;
  - the parameters the client completes, as Commando and plugins that edit `AvailableCommandsPacket` set them.
- **Scheduler**:
  - delayed and repeating tasks on the server thread;
  - async tasks on a pool of worker threads.
- **Threads and databases**:
  - `pocketmine\thread\Thread` and `Worker` on pmmpthread, as PocketMine-MP runs them, with the server's sleeper;
  - SQLite3, PDO and mysqli, so libasynql and the plugins built on it keep their data;
  - promises.
- **Players and worlds**:
  - messages, titles, forms, inventories and teleports;
  - blocks, entities and worlds, mapped to their Falcon counterparts.

Plugins keep their own license. Only the code in this repository is under the license below.

## What will not work

- **Raw packets**: plugins that read or send network packets directly rely on PocketMine-MP's own protocol
  classes and its network threads. Some packets can be bridged one at a time, but full coverage is not a goal.
- **Server internals**: classes outside the public API, or plugins that patch PocketMine-MP with reflection,
  are not supported.
- **Speed of the plugin itself**: Falcon's world, ticking and network get faster, but a plugin's own PHP code
  runs as fast as it did on PocketMine-MP.

## Usage

Put the compatibility plugin in Falcon's `plugins/` folder, then put your PocketMine-MP plugins in
`plugins/pmmp-compat/plugins/`:

```text
plugins/
  pmmp-compat/
    plugin.json
    pmmp-compat.so
    plugins/
      EconomyAPI.phar
      MyCustomPlugin/
        plugin.yml
        src/
```

Each plugin's data folder is `plugins/pmmp-compat/plugin_data/<name>/`, as PocketMine-MP lays it out, so
existing `config.yml` files can be copied over.

### GameSpy Query

Set `enable-query=true` in `server.properties` to serve GameSpy short and full statistics.
The listener defaults to UDP on `server-port` and binds to `0.0.0.0`. Set `query-ip` to choose
a bind address. With RakNet, set `query-port` to a different UDP port from the game listener.
Full statistics include data set by plugins through `QueryRegenerateEvent`.


### ProperDuels 3.0.0

The original Poggit release repeats the SQLite `Kits.Enabled` migration on restart. Create a patched
copy with PHP CLI and Phar enabled:

```sh
php -d phar.readonly=0 scripts/patch-properduels.php ProperDuels_3.0.0.phar ProperDuels_3.0.0-patched.phar
```

Stop the server and replace the original PHAR in the plugins folder with the patched copy. Keep the
existing `plugin_data/ProperDuels` directory so the plugin can reuse its database.

## Building

Requires CMake 3.16+, a C++20 compiler and the thread-safe PHP 8 embed library the scripts set up:
`scripts/build-php.sh` on Linux and macOS (it needs autoconf and the sqlite3, gmp and openssl development files),
`scripts/fetch-php-windows.ps1` on Windows.

```
scripts/build-php.sh
cmake -B build -G Ninja
cmake --build build
cmake --install build --prefix dist
```

## Testing

The CI workflow builds Falcon at a pinned revision and runs the smoke suite on Linux, macOS, and Windows.
The test server starts in a temporary directory and must stop cleanly before the test passes.

```sh
scripts/smoke-test.sh /path/to/FalconServer dist/pmmp-compat
scripts/lifecycle-plugin-test.sh /path/to/FalconServer dist/pmmp-compat /path/to/StarterKit.phar /path/to/ScoreHud.phar
```

The lifecycle test uses StarterKit 4.0.1 and ScoreHud 8.0.0. It runs both plugins twice against the same world
and plugin data to verify that the kit is only issued once and the scoreboard works after restarting.

## Related repositories

- [Falcon](https://github.com/Falcon-MC/Falcon) - the server
- [PluginAPI](https://github.com/Falcon-MC/PluginAPI) - the plugin API this plugin is built on

## Licensing information

Falcon PocketMine Compatibility is licensed under the [GNU Lesser General Public License v3.0](LICENSE), which
supplements the [GNU General Public License v3.0](COPYING), the same license as PocketMine-MP. You may use,
modify and redistribute it, as long as changes to it stay under the same license.

It bundles [symfony/yaml](https://github.com/symfony/yaml) under the MIT license, in `php/vendor/`.

It is not affiliated with or approved by PocketMine-MP, Mojang or Microsoft. All brands and trademarks belong
to their respective owners.
