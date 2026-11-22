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
	<img src="https://img.shields.io/badge/status-early%20development-orange" alt="Status">
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
  - `onCommand`, permissions and aliases.
- **Scheduler**:
  - delayed and repeating tasks on the server thread;
  - async tasks on a worker pool.
- **Players and worlds**:
  - messages, titles, forms, inventories and teleports;
  - blocks, entities and worlds, mapped to their Falcon counterparts.

Plugins keep their own license. Only the code in this repository is under the license below.

## Status

Early development: plugins load, but most of the API they call is not there yet. The API is covered in the
order the most used plugins need it. That order is measured on the most downloaded plugins of
[Poggit](https://poggit.pmmp.io), not guessed.

| Area | State |
|---|---|
| `plugin.yml`, `.phar` and source plugins, class loading, load order | Done |
| Plugin lifecycle (`onLoad`, `onEnable`, `onDisable`) | In progress |
| Events | Planned |
| Commands and permissions | Planned |
| Scheduler and async tasks | Planned |
| `Config` | Planned |
| Players, inventories and forms | Planned |
| Worlds, blocks and entities | Planned |

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

## Building

Requires CMake 3.16+, a C++17 compiler and the PHP 8 embed library (`libphp`, built with `--enable-embed`).

```
cmake -B build -G Ninja
cmake --build build
```

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
