<?php

declare(strict_types=1);

namespace pocketmine\plugin;

use pocketmine\thread\ThreadSafeClassLoader;

/** Plugins packed as .phar files, as Poggit builds them */
class PharPluginLoader implements PluginLoader{
    private const PROTOCOL = 'phar://';

    public function __construct(
        private ThreadSafeClassLoader $loader
    ){
    }

    public function canLoadPlugin(string $path) : bool{
        return is_file($path) && str_ends_with(strtolower($path), '.phar');
    }

    public function loadPlugin(string $file) : void{
        $description = $this->getPluginDescription($file);
        if($description !== null){
            $this->loader->addPath($description->getSrcNamespacePrefix(), self::withProtocol($file) . '/src');
        }
    }

    public function getPluginDescription(string $file) : ?PluginDescription{
        $manifest = self::withProtocol($file) . '/plugin.yml';
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
        return self::PROTOCOL;
    }

    private static function withProtocol(string $file) : string{
        $file = str_replace('\\', '/', $file);
        return str_starts_with($file, self::PROTOCOL) ? $file : self::PROTOCOL . $file;
    }
}
