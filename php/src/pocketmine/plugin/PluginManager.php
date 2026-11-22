<?php

declare(strict_types=1);

namespace pocketmine\plugin;

use pmmpcompat\Log;

/**
 * Loads, enables and disables plugins, in the order their dependencies need.
 *
 * Loading follows PocketMine-MP: a plugin loads once its hard dependencies (depend) are loaded and its soft ones
 * (softdepend, and the plugins naming it in loadbefore) are loaded or missing. What is left once nothing more can
 * load either needs a missing plugin or is part of a cycle.
 */
class PluginManager{
    private const FATAL = 'a fatal error or exit() stopped it, see above';

    /** @var Plugin[] by name, in load order */
    private array $plugins = [];
    /** @var Plugin[] by name, in enable order */
    private array $enabledPlugins = [];
    /** @var array<string, array<string, true>> plugin => the enabled plugins depending on it */
    private array $pluginDependents = [];
    /** @var PluginLoader[] by class */
    private array $fileAssociations = [];
    private bool $loadPluginsGuard = false;

    public function __construct(
        private string $apiVersion,
        private ?string $pluginDataDirectory
    ){
        if($pluginDataDirectory !== null && !is_dir($pluginDataDirectory)){
            mkdir($pluginDataDirectory, 0777, true);
        }
    }

    public function getPlugin(string $name) : ?Plugin{
        return $this->plugins[$name] ?? null;
    }

    /** @return Plugin[] */
    public function getPlugins() : array{
        return $this->plugins;
    }

    public function registerInterface(PluginLoader $loader) : void{
        $this->fileAssociations[get_class($loader)] = $loader;
    }

    private function getDataDirectory(string $pluginPath, string $pluginName) : string{
        if($this->pluginDataDirectory !== null){
            return $this->pluginDataDirectory . '/' . $pluginName;
        }
        return dirname($pluginPath) . '/' . $pluginName;
    }

    /** @return Plugin[] the plugins loaded by this call, by name */
    public function loadPlugins(string $path, int &$loadErrorCount = 0) : array{
        if($this->loadPluginsGuard){
            throw new \LogicException(__METHOD__ . '() cannot be called from within itself');
        }
        $this->loadPluginsGuard = true;
        try{
            return $this->resolveAndLoad($path, $loadErrorCount);
        }finally{
            $this->loadPluginsGuard = false;
        }
    }

    /** @return Plugin[] */
    private function resolveAndLoad(string $path, int &$loadErrorCount) : array{
        $triage = $this->triagePlugins($path, $loadErrorCount);
        $candidates = $triage['plugins'];
        $dependencies = $triage['dependencies'];
        $softDependencies = $triage['softDependencies'];
        $loaded = [];

        while(count($candidates) > 0){
            $loadedThisLoop = 0;
            foreach($candidates as $name => $entry){
                $this->settleDependencies($name, $dependencies, $loaded);
                $this->settleDependencies($name, $softDependencies, $loaded);
                if(isset($dependencies[$name]) || isset($softDependencies[$name])){
                    continue;
                }
                unset($candidates[$name]);
                $loadedThisLoop++;
                $plugin = $this->internalLoadPlugin($entry['file'], $entry['loader'], $entry['description']);
                if($plugin !== null){
                    $loaded[$name] = $plugin;
                }else{
                    $loadErrorCount++;
                }
            }
            if($loadedThisLoop > 0){
                continue;
            }

            // a soft dependency nobody provides is dropped, which may let the rest resolve
            foreach($candidates as $name => $entry){
                if(!isset($softDependencies[$name]) || isset($dependencies[$name])){
                    continue;
                }
                foreach($softDependencies[$name] as $key => $dependency){
                    if($this->getPlugin($dependency) === null && !isset($candidates[$dependency])){
                        unset($softDependencies[$name][$key]);
                    }
                }
                if(count($softDependencies[$name]) === 0){
                    unset($softDependencies[$name]);
                    continue 2;
                }
            }

            foreach($candidates as $name => $entry){
                if(!isset($dependencies[$name])){
                    continue;
                }
                $unknown = [];
                foreach($dependencies[$name] as $dependency){
                    if($this->getPlugin($dependency) === null && !isset($candidates[$dependency])){
                        $unknown[$dependency] = $dependency;
                    }
                }
                if(count($unknown) > 0){
                    Log::critical("Could not load plugin '$name': Unknown dependency: " . implode(', ', $unknown));
                    unset($candidates[$name]);
                    $loadErrorCount++;
                }
            }

            foreach($candidates as $name => $entry){
                Log::critical("Could not load plugin '$name': Circular dependency detected");
                $loadErrorCount++;
            }
            break;
        }
        return $loaded;
    }

    /**
     * @return array{
     *     plugins: array<string, array{file: string, loader: PluginLoader, description: PluginDescription}>,
     *     dependencies: array<string, string[]>,
     *     softDependencies: array<string, string[]>
     * }
     */
    private function triagePlugins(string $path, int &$loadErrorCount) : array{
        $plugins = [];
        $dependencies = [];
        $softDependencies = [];

        if(is_dir($path)){
            $files = iterator_to_array(new \FilesystemIterator($path,
                \FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS), false);
            // plugins must say what they load after, not count on the folder's order
            shuffle($files);
        }elseif(is_file($path)){
            $files = [(string) realpath($path)];
        }else{
            $files = [];
        }

        $checker = new PluginLoadabilityChecker($this->apiVersion);
        foreach($this->fileAssociations as $loader){
            foreach($files as $file){
                $file = str_replace('\\', '/', $file);
                if(!$loader->canLoadPlugin($file)){
                    continue;
                }
                try{
                    $description = $loader->getPluginDescription($file);
                }catch(PluginDescriptionParseException $e){
                    Log::critical("Could not load plugin '$file': Invalid plugin manifest: " . $e->getMessage());
                    $loadErrorCount++;
                    continue;
                }catch(\Throwable $e){
                    Log::critical("Could not load plugin '$file': " . $e->getMessage());
                    Log::exception($e);
                    $loadErrorCount++;
                    continue;
                }
                if($description === null){
                    continue;
                }

                $name = $description->getName();
                $problem = $checker->check($description);
                if($problem !== null){
                    Log::critical("Could not load plugin '$name': $problem");
                    $loadErrorCount++;
                    continue;
                }
                if(isset($plugins[$name]) || $this->getPlugin($name) !== null){
                    Log::critical("Could not load plugin '$name': plugin exists");
                    $loadErrorCount++;
                    continue;
                }
                if(str_contains($name, ' ')){
                    Log::warning("Plugin '$name' uses spaces in its name, this is discouraged");
                }

                $plugins[$name] = ['file' => $file, 'loader' => $loader, 'description' => $description];
                $softDependencies[$name] = array_merge($softDependencies[$name] ?? [], $description->getSoftDepend());
                $dependencies[$name] = $description->getDepend();
                // loadbefore makes this plugin a soft dependency of the one it names
                foreach($description->getLoadBefore() as $before){
                    $softDependencies[$before][] = $name;
                }
            }
        }
        return ['plugins' => $plugins, 'dependencies' => $dependencies, 'softDependencies' => $softDependencies];
    }

    /**
     * Drops from a plugin's list the dependencies already loaded; the list goes once empty.
     *
     * @param array<string, string[]> $lists
     * @param Plugin[] $loaded
     */
    private function settleDependencies(string $name, array &$lists, array $loaded) : void{
        if(!isset($lists[$name])){
            return;
        }
        foreach($lists[$name] as $key => $dependency){
            if(isset($loaded[$dependency]) || $this->getPlugin($dependency) !== null){
                unset($lists[$name][$key]);
            }
        }
        if(count($lists[$name]) === 0){
            unset($lists[$name]);
        }
    }

    private function internalLoadPlugin(string $path, PluginLoader $loader, PluginDescription $description) : ?Plugin{
        $name = $description->getName();
        Log::info('Loading ' . $description->getFullName());

        $dataFolder = $this->getDataDirectory($path, $name);
        if(file_exists($dataFolder) && !is_dir($dataFolder)){
            Log::critical("Could not load plugin '$name': Projected data directory $dataFolder exists and is not a directory");
            return null;
        }
        if(!file_exists($dataFolder)){
            mkdir($dataFolder, 0777, true);
        }

        $prefixed = $loader->getAccessProtocol() . $path;
        $loader->loadPlugin($prefixed);

        $mainClass = $description->getMain();
        $plugin = null;
        $problem = null;
        try{
            $build = static function() use ($mainClass, $loader, $description, $dataFolder, $prefixed, &$plugin,
                &$problem) : void{
                if(!class_exists($mainClass, true)){
                    $problem = 'Main class not found';
                }elseif(!is_a($mainClass, Plugin::class, true)){
                    $problem = 'Main class is not an instance of ' . Plugin::class;
                }elseif(!(new \ReflectionClass($mainClass))->isInstantiable()){
                    $problem = 'Main class must not be abstract';
                }else{
                    // onLoad() runs inside the constructor
                    $plugin = new $mainClass($loader, null, $description, $dataFolder, $prefixed,
                        new DiskResourceProvider($prefixed . '/resources/'));
                }
            };
            // a missing trait or parent class is a fatal error, not an exception
            $completed = falcon_guard($build);
        }catch(\Throwable $e){
            Log::critical("Could not load plugin '$name': " . $e->getMessage());
            Log::exception($e);
            return null;
        }
        if(!$completed){
            Log::critical("Could not load plugin '$name': " . self::FATAL);
            return null;
        }
        if($problem !== null || !$plugin instanceof Plugin){
            Log::critical("Could not load plugin '$name': " . ($problem ?? 'Main class not found'));
            return null;
        }
        $this->plugins[$name] = $plugin;
        return $plugin;
    }

    public function isPluginEnabled(Plugin $plugin) : bool{
        return isset($this->plugins[$plugin->getDescription()->getName()]) && $plugin->isEnabled();
    }

    /** enables the loaded plugins of one order, in the order they loaded */
    public function enablePlugins(PluginEnableOrder $order) : bool{
        $allEnabled = true;
        foreach($this->plugins as $plugin){
            if(!$plugin->isEnabled() && $plugin->getDescription()->getOrder() === $order){
                $allEnabled = $this->enablePlugin($plugin) && $allEnabled;
            }
        }
        return $allEnabled;
    }

    public function enablePlugin(Plugin $plugin) : bool{
        if($plugin->isEnabled()){
            return true;
        }
        $description = $plugin->getDescription();
        $name = $description->getName();
        Log::info('Enabling ' . $description->getFullName());
        try{
            if(!falcon_guard(static fn() => $plugin->onEnableStateChange(true))){
                Log::critical("Could not enable plugin '$name': " . self::FATAL);
                $this->forget($plugin);
                return false;
            }
        }catch(DisablePluginException){
            $this->disablePlugin($plugin);
        }catch(\Throwable $e){
            Log::critical("Could not enable plugin '$name': " . $e->getMessage());
            Log::exception($e);
            // the plugin is half enabled: onDisable() lets it undo what it did
            $this->disablePlugin($plugin);
            return false;
        }

        if(!$plugin->isEnabled()){
            Log::critical("Could not enable plugin '$name': Plugin disabled itself when attempting to enable it");
            return false;
        }
        $this->enabledPlugins[$name] = $plugin;
        foreach($description->getDepend() as $dependency){
            $this->pluginDependents[$dependency][$name] = true;
        }
        foreach($description->getSoftDepend() as $dependency){
            if(isset($this->plugins[$dependency])){
                $this->pluginDependents[$dependency][$name] = true;
            }
        }
        return true;
    }

    /** disables every plugin, each after the plugins depending on it */
    public function disablePlugins() : void{
        while(count($this->enabledPlugins) > 0){
            $disabledThisLoop = 0;
            foreach($this->enabledPlugins as $name => $plugin){
                if(!$plugin->isEnabled()){
                    unset($this->enabledPlugins[$name]);
                    continue;
                }
                if(isset($this->pluginDependents[$name]) && count($this->pluginDependents[$name]) > 0){
                    continue;
                }
                $this->disablePlugin($plugin);
                $disabledThisLoop++;
            }
            if($disabledThisLoop === 0){
                // dependents that never let go: whatever is left goes in enable order
                foreach($this->enabledPlugins as $plugin){
                    $this->disablePlugin($plugin);
                }
            }
        }
    }

    public function disablePlugin(Plugin $plugin) : void{
        if(!$plugin->isEnabled()){
            return;
        }
        $name = $plugin->getDescription()->getName();
        Log::info('Disabling ' . $plugin->getDescription()->getFullName());
        unset($this->enabledPlugins[$name]);
        foreach($this->pluginDependents as $dependency => $dependents){
            unset($this->pluginDependents[$dependency][$name]);
            if(count($this->pluginDependents[$dependency]) === 0){
                unset($this->pluginDependents[$dependency]);
            }
        }
        try{
            if(!falcon_guard(static fn() => $plugin->onEnableStateChange(false))){
                Log::critical("Error while disabling plugin '$name': " . self::FATAL);
                $this->forget($plugin);
            }
        }catch(\Throwable $e){
            Log::critical("Error while disabling plugin '$name': " . $e->getMessage());
            Log::exception($e);
        }
    }

    /** a fatal error left the plugin in an unknown state: it is dropped, never called again */
    private function forget(Plugin $plugin) : void{
        $name = $plugin->getDescription()->getName();
        unset($this->plugins[$name], $this->enabledPlugins[$name]);
        foreach($this->pluginDependents as $dependency => $dependents){
            unset($this->pluginDependents[$dependency][$name]);
            if(count($this->pluginDependents[$dependency]) === 0){
                unset($this->pluginDependents[$dependency]);
            }
        }
    }
}
