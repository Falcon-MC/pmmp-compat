<?php

declare(strict_types=1);

namespace pocketmine\plugin;

use pocketmine\command\Command;
use pocketmine\command\CommandExecutor;
use pocketmine\command\CommandSender;
use pocketmine\command\PluginCommand;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\scheduler\TaskScheduler;
use pocketmine\Server;
use pocketmine\utils\Config;
use Symfony\Component\Filesystem\Path;

/**
 * The class a plugin's main class extends: its lifecycle, logger, data folder, config.yml, resources and the
 * commands plugin.yml declares, and its scheduler.
 */
abstract class PluginBase implements Plugin, CommandExecutor{
    private bool $isEnabled = false;

    private string $resourceFolder;

    private ?Config $config = null;
    private string $configFile;

    private PluginLogger $logger;
    private TaskScheduler $scheduler;

    public function __construct(
        private PluginLoader $loader,
        private Server $server,
        private PluginDescription $description,
        private string $dataFolder,
        private string $file,
        private ResourceProvider $resourceProvider
    ){
        $this->dataFolder = rtrim($dataFolder, '/' . DIRECTORY_SEPARATOR) . '/';
        $this->file = rtrim($file, '/' . DIRECTORY_SEPARATOR) . '/';
        $this->resourceFolder = Path::join($this->file, 'resources') . '/';

        $this->configFile = Path::join($this->dataFolder, 'config.yml');

        $prefix = $this->description->getPrefix();
        $this->logger = new PluginLogger($server->getLogger(), $prefix !== '' ? $prefix : $this->getName());
        $this->scheduler = new TaskScheduler($this->getFullName());

        $this->onLoad();

        $this->registerYamlCommands();
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
            if($this->isEnabled){
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

    public function getLogger() : \AttachableLogger{
        return $this->logger;
    }

    private function registerYamlCommands() : void{
        $pluginCommands = [];
        foreach($this->description->getCommands() as $key => $data){
            if(str_contains($key, ':')){
                $this->logger->error($this->server->getLanguage()->translate(
                    KnownTranslationFactory::pocketmine_plugin_commandError($key, $this->description->getFullName(), ':')));
                continue;
            }

            $command = new PluginCommand($key, $this, $this);
            if(($description = $data->getDescription()) !== null){
                $command->setDescription($description);
            }
            if(($usageMessage = $data->getUsageMessage()) !== null){
                $command->setUsage($usageMessage);
            }

            $aliases = [];
            foreach($data->getAliases() as $alias){
                if(str_contains($alias, ':')){
                    $this->logger->error($this->server->getLanguage()->translate(
                        KnownTranslationFactory::pocketmine_plugin_aliasError($alias, $this->description->getFullName(), ':')));
                    continue;
                }
                $aliases[] = $alias;
            }
            $command->setAliases($aliases);
            $command->setPermission($data->getPermission());
            if(($permissionDeniedMessage = $data->getPermissionDeniedMessage()) !== null){
                $command->setPermissionMessage($permissionDeniedMessage);
            }
            $pluginCommands[] = $command;
        }

        if(count($pluginCommands) > 0){
            $this->server->getCommandMap()->registerAll($this->description->getName(), $pluginCommands);
        }
    }

    /**
     * A command this plugin declared in plugin.yml, by its name or by its prefixed one.
     *
     * @return Command|PluginOwned|null
     * @phpstan-return (Command&PluginOwned)|null
     */
    public function getCommand(string $name){
        $command = $this->server->getPluginCommand($name);
        if($command === null || $command->getOwningPlugin() !== $this){
            $command = $this->server->getPluginCommand(strtolower($this->description->getName()) . ':' . $name);
        }
        if($command instanceof PluginOwned && $command->getOwningPlugin() === $this){
            return $command;
        }
        return null;
    }

    /**
     * @param string[] $args
     */
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
        return false;
    }

    public function getResourceFolder() : string{
        return $this->resourceFolder;
    }

    public function getResourcePath(string $filename) : string{
        return Path::join($this->getResourceFolder(), $filename);
    }

    /**
     * @return null|resource a stream of the resource, null when the plugin has none by that name
     */
    public function getResource(string $filename){
        return $this->resourceProvider->getResource($filename);
    }

    /** copies a resource into the data folder; false when it is missing or already there */
    public function saveResource(string $filename, bool $replace = false) : bool{
        if(trim($filename) === ''){
            return false;
        }

        $source = Path::join($this->resourceFolder, $filename);
        if(!file_exists($source)){
            return false;
        }

        $destination = Path::join($this->dataFolder, $filename);
        if(file_exists($destination) && !$replace){
            return false;
        }

        if(!file_exists(dirname($destination))){
            mkdir(dirname($destination), 0755, true);
        }

        return copy($source, $destination);
    }

    /**
     * @return \SplFileInfo[] by path inside resources/
     */
    public function getResources() : array{
        return $this->resourceProvider->getResources();
    }

    public function getConfig() : Config{
        if($this->config === null){
            $this->reloadConfig();
        }
        return $this->config;
    }

    public function saveConfig() : void{
        $this->getConfig()->save();
    }

    /** copies resources/config.yml into the data folder unless a config.yml is already there */
    public function saveDefaultConfig() : bool{
        if(!file_exists($this->configFile)){
            return $this->saveResource('config.yml', false);
        }
        return false;
    }

    public function reloadConfig() : void{
        $this->saveDefaultConfig();
        $this->config = new Config($this->configFile);
    }

    final public function getServer() : Server{
        return $this->server;
    }

    final public function getName() : string{
        return $this->description->getName();
    }

    final public function getFullName() : string{
        return $this->description->getFullName();
    }

    /** the plugin's own files, inside its phar or folder, ending with a slash */
    protected function getFile() : string{
        return $this->file;
    }

    public function getPluginLoader() : PluginLoader{
        return $this->loader;
    }

    public function getScheduler() : TaskScheduler{
        return $this->scheduler;
    }
}
