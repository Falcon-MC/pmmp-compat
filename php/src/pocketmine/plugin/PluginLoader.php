<?php

declare(strict_types=1);

namespace pocketmine\plugin;

/** Reads one kind of plugin file: a phar, a source folder */
interface PluginLoader{
    public function canLoadPlugin(string $path) : bool;

    /** makes the plugin's classes loadable; $file carries the access protocol */
    public function loadPlugin(string $file) : void;

    /** null when the file holds no plugin.yml */
    public function getPluginDescription(string $file) : ?PluginDescription;

    /** what goes before a plugin's path to read files inside it, such as phar:// */
    public function getAccessProtocol() : string;
}
