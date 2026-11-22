<?php

declare(strict_types=1);

namespace pocketmine\plugin;

/**
 * What the server needs of a plugin's main class.
 *
 */
interface Plugin{
    public function isEnabled() : bool;

    /** called by the plugin manager only: onEnable() or onDisable() runs from here */
    public function onEnableStateChange(bool $enabled) : void;

    /** where the plugin keeps its files, ending with a slash */
    public function getDataFolder() : string;

    public function getDescription() : PluginDescription;

    public function getName() : string;

    public function getLogger() : \AttachableLogger;

    public function getServer() : \pocketmine\Server;

    public function getPluginLoader() : PluginLoader;

    public function getScheduler() : \pocketmine\scheduler\TaskScheduler;
}
