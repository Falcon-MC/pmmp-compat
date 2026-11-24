# Moving PocketMine-MP plugins to Falcon

## Prepare a staging server

1. Stop the old server and take a backup of its plugin files, plugin data, worlds, player data and server configuration. Keep the backup with the old server version.
2. Set up Falcon in a separate directory. Download the pmmp-compat archive for your operating system and CPU architecture from [Releases](https://github.com/Falcon-MC/pmmp-compat/releases). Use the Falcon revision specified in the release notes.
3. Extract the archive so that `plugin.json`, the native library and the `php/` directory are under `plugins/pmmp-compat/`. Keep the bundled libraries and PHP files together.

## Copy plugins and data

| From the PocketMine-MP server | To the Falcon server |
| --- | --- |
| `plugins/Example.phar` | `plugins/pmmp-compat/plugins/Example.phar` |
| A source plugin with `plugin.yml` and `src/` | `plugins/pmmp-compat/plugins/Example/` |
| `plugin_data/Example/` | `plugins/pmmp-compat/plugin_data/Example/` |

The data directory uses the plugin name from `plugin.yml`, including its case. Copy declared dependencies and any data directories they own as well. If a plugin uses an external database, configure staging to use a separate copy so tests avoid changing production data.

Configure the port, default world, authentication, access lists and permissions in Falcon. Test copied worlds and player data on staging before using them. PocketMine-MP APIs that access server internals, raw packets or storage can require changes.

## Scan the plugins folder

Run the report using PHP CLI with Phar support from the pmmp-compat folder:

```sh
php tools/compatibility-report.php plugins
php tools/compatibility-report.php plugins --json
```

Use `scripts/compatibility-report.php` from a repository checkout. Each result lists PocketMine-MP classes referenced in PHP source that are absent from the bundled API. An empty list means the named classes were found. A scan error means the report could not inspect that archive or folder. The scan reads files without loading the plugins. Dynamic class names, behavior within existing classes and external plugin dependencies require runtime testing. The server refuses plugins that require API 3 or 4 and logs the versions requested.

## Validate and switch over

Start staging and check which plugins enabled. Connect, exercise critical commands and workflows, then stop and restart the server on the same staging data. Confirm that inventories, plugin databases and world changes persist after restart. Repeat the copy from a fresh, stopped production backup when switching servers. If you revert, stop Falcon and start the old server with its matching backup.
