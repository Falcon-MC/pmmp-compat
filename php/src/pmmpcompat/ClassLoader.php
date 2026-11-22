<?php

declare(strict_types=1);

namespace pmmpcompat;

/**
 * Finds a class's file the first time it is used, as PocketMine-MP's class loader does.
 *
 * A path added with a namespace prefix is PSR-4: the prefix maps to the folder. A path added without one holds the
 * full namespace as folders. Paths may be inside a phar.
 */
final class ClassLoader{
    /** @var array<string, list<string>> namespace prefix ending in a backslash => folders */
    private array $prefixed = [];
    /** @var list<string> */
    private array $unprefixed = [];

    public function register() : void{
        spl_autoload_register($this->loadClass(...));
    }

    public function addPath(string $namespacePrefix, string $path) : void{
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $namespacePrefix = trim($namespacePrefix, '\\');
        if($namespacePrefix === ''){
            $this->unprefixed[] = $path;
        }else{
            $this->prefixed[$namespacePrefix . '\\'][] = $path;
        }
    }

    public function findClass(string $name) : ?string{
        $name = ltrim($name, '\\');
        foreach($this->prefixed as $prefix => $paths){
            if(strncasecmp($name, $prefix, strlen($prefix)) !== 0){
                continue;
            }
            $relative = str_replace('\\', '/', substr($name, strlen($prefix))) . '.php';
            foreach($paths as $path){
                if(is_file($path . '/' . $relative)){
                    return $path . '/' . $relative;
                }
            }
        }
        $relative = str_replace('\\', '/', $name) . '.php';
        foreach($this->unprefixed as $path){
            if(is_file($path . '/' . $relative)){
                return $path . '/' . $relative;
            }
        }
        return null;
    }

    private function loadClass(string $name) : void{
        $file = $this->findClass($name);
        if($file !== null){
            require $file;
        }
    }
}
