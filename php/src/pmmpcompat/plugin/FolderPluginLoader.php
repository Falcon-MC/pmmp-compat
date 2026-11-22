<?php

declare(strict_types=1);

namespace pmmpcompat\plugin;

use pocketmine\thread\ThreadSafeClassLoader;
use pocketmine\plugin\PluginDescription;
use pocketmine\plugin\PluginException;
use pocketmine\plugin\PluginLoader;

/** Plugins left as source, a folder with plugin.yml and src/, as PocketMine-MP's DevTools runs them */
final class FolderPluginLoader implements PluginLoader{
    public function __construct(
        private ThreadSafeClassLoader $loader
    ){
    }

    public function canLoadPlugin(string $path) : bool{
        return is_dir($path) && is_file($path . '/plugin.yml') && is_dir($path . '/src');
    }

    public function loadPlugin(string $file) : void{
        $description = $this->getPluginDescription($file);
        if($description !== null){
            $this->loader->addPath($description->getSrcNamespacePrefix(), $file . '/src');
        }
    }

    public function getPluginDescription(string $file) : ?PluginDescription{
        $manifest = $file . '/plugin.yml';
        if(!is_file($manifest)){
            return null;
        }
        $text = file_get_contents($manifest);
        if($text === false){
            throw new PluginException("could not read $manifest");
        }
        return new PluginDescription($text);
    }

    public function getAccessProtocol() : string{
        return '';
    }
}
