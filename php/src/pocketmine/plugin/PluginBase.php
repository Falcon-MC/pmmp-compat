<?php

declare(strict_types=1);

namespace pocketmine\plugin;

/**
 * The class a plugin's main class extends.
 *
 * Only its lifecycle for now: onLoad(), onEnable() and onDisable(), the data folder and the description.
 */
abstract class PluginBase implements Plugin{
    private bool $isEnabled = false;
    private string $dataFolder;
    private string $file;
    private string $resourceFolder;

    /**
     * @param mixed $server the server, once it exists in this runtime
     * @param mixed $resourceProvider the plugin's resources, once they exist in this runtime
     */
    public function __construct(
        private PluginLoader $loader,
        private mixed $server,
        private PluginDescription $description,
        string $dataFolder,
        string $file,
        private mixed $resourceProvider = null
    ){
        $this->dataFolder = rtrim(str_replace('\\', '/', $dataFolder), '/') . '/';
        // phar:// paths keep their protocol's slashes
        $this->file = rtrim(str_replace('\\', '/', $file), '/') . '/';
        $this->resourceFolder = $this->file . 'resources/';
        $this->onLoad();
    }

    /** called once the plugin is loaded, before any plugin is enabled */
    protected function onLoad() : void{
    }

    protected function onEnable() : void{
    }

    /** also called when the plugin is disabled because onEnable() failed */
    protected function onDisable() : void{
    }

    final public function isEnabled() : bool{
        return $this->isEnabled;
    }

    final public function onEnableStateChange(bool $enabled) : void{
        if($this->isEnabled !== $enabled){
            $this->isEnabled = $enabled;
            if($enabled){
                $this->onEnable();
            }else{
                $this->onDisable();
            }
        }
    }

    final public function isDisabled() : bool{
        return !$this->isEnabled;
    }

    final public function getDataFolder() : string{
        return $this->dataFolder;
    }

    final public function getDescription() : PluginDescription{
        return $this->description;
    }

    final public function getName() : string{
        return $this->description->getName();
    }

    final public function getFullName() : string{
        return $this->description->getFullName();
    }

    final public function getPluginLoader() : PluginLoader{
        return $this->loader;
    }

    /** the plugin's own files, inside its phar or folder */
    final protected function getFile() : string{
        return $this->file;
    }

    final public function getResourceFolder() : string{
        return $this->resourceFolder;
    }
}
