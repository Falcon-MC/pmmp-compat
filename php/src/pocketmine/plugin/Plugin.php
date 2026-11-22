<?php

declare(strict_types=1);

namespace pocketmine\plugin;

/**
 * What the plugin manager needs of a plugin's main class.
 *
 * Only the loading part for now; the logger, the server, the scheduler and the resources come with the plugin base.
 */
interface Plugin{
    public function isEnabled() : bool;

    /** called by the plugin manager only: onEnable() or onDisable() runs from here */
    public function onEnableStateChange(bool $enabled) : void;

    /** where the plugin keeps its files, ending with a slash */
    public function getDataFolder() : string;

    public function getDescription() : PluginDescription;

    public function getName() : string;

    public function getPluginLoader() : PluginLoader;
}
