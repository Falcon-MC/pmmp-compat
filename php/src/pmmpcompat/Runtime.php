<?php

declare(strict_types=1);

namespace pmmpcompat;

use pmmpcompat\plugin\FolderPluginLoader;
use pocketmine\errorhandler\ErrorToExceptionHandler;
use pocketmine\plugin\PharPluginLoader;
use pocketmine\plugin\PluginEnableOrder;
use pocketmine\permission\DefaultPermissions;
use pocketmine\plugin\PluginManager;
use pocketmine\Server;
use pocketmine\timings\Timings;
use pocketmine\thread\ThreadSafeClassLoader;
use pocketmine\thread\ThreadManager;
use pocketmine\utils\Filesystem;

/** Starts and stops the PocketMine-MP plugins inside Falcon. */
final class Runtime{
    /** The PocketMine-MP API this runtime answers to in plugin.yml's api */
    public const API_VERSION = '5.44.3';

    private static ?PluginManager $plugins = null;
    private static ?ServerLogger $logger = null;

    private function __construct(){
    }

    /**
     * @param string $runtimeFolder this runtime's PHP files
     * @param string $pluginFolder the folder holding plugins/ and plugin_data/
     */
    public static function start(string $runtimeFolder, string $pluginFolder) : void{
        if(self::$plugins !== null){
            throw new \LogicException('the runtime is already started');
        }
        // thread-safe, as PocketMine-MP's: threads plugins start register the same loader
        $classes = new ThreadSafeClassLoader();
        $classes->addPath('pocketmine', $runtimeFolder . '/src/pocketmine');
        $classes->addPath('pmmpcompat', $runtimeFolder . '/src/pmmpcompat');
        $classes->addPath('Symfony\\Component\\Yaml', $runtimeFolder . '/vendor/symfony/yaml');
        $classes->addPath('Symfony\\Component\\Filesystem', $runtimeFolder . '/vendor/symfony/filesystem');
        $classes->addPath('DaveRandom\\CallbackValidator', $runtimeFolder . '/vendor/pocketmine/callback-validator');
        foreach(['math', 'nbt', 'color'] as $library){
            $classes->addPath('pocketmine\\' . $library, $runtimeFolder . '/vendor/pocketmine/' . $library);
        }
        $classes->addPath('pocketmine\\network\\mcpe\\protocol', $runtimeFolder . '/vendor/pocketmine/bedrock-protocol');
        $classes->addPath('Ramsey\\Uuid', $runtimeFolder . '/vendor/ramsey/uuid');
        $classes->addPath('Ramsey\\Collection', $runtimeFolder . '/vendor/ramsey/collection');
        $classes->addPath('Brick\\Math', $runtimeFolder . '/vendor/brick/math');
        // the log library's classes are global: \Logger, \PrefixedLogger
        $classes->addPath('', $runtimeFolder . '/src/log');
        $classes->addPath('pocketmine\\snooze', $runtimeFolder . '/vendor/pocketmine/snooze');
        $classes->register();
        require_once $runtimeFolder . '/vendor/autoload.php';
        self::definePaths($runtimeFolder, $pluginFolder);
        // as PocketMine-MP does: warnings and notices are thrown, plugins catch them as \ErrorException
        ErrorToExceptionHandler::set();
        self::$logger = new ServerLogger();
        \GlobalLogger::set(self::$logger);

        $pluginsPath = $pluginFolder . '/plugins';
        $dataPath = $pluginFolder . '/plugin_data';
        foreach([$pluginsPath, $dataPath] as $path){
            if(!is_dir($path)){
                mkdir($path, 0777, true);
            }
        }

        Timings::init();
        DefaultPermissions::registerCorePermissions();
        $server = new Server(self::$logger, $classes, \pocketmine\DATA, \pocketmine\PLUGIN_PATH, $dataPath);
        $manager = $server->getPluginManager();
        $manager->registerInterface(new PharPluginLoader($classes));
        $manager->registerInterface(new FolderPluginLoader($classes));
        self::$plugins = $manager;

        $errors = 0;
        $manager->loadPlugins($pluginsPath, $errors);
        // the server is up by the time Falcon enables this plugin: both orders run now, startup first
        $manager->enablePlugins(PluginEnableOrder::STARTUP);
        $manager->enablePlugins(PluginEnableOrder::POSTWORLD);
        Bridge::listen();
        $count = count($manager->getPlugins());
        Log::info($count . ' PocketMine-MP plugin' . ($count === 1 ? '' : 's') . ' loaded'
            . ($errors > 0 ? ', ' . $errors . ' could not be' : ''));
    }

    // the constants PocketMine-MP defines at startup; the server's data folder is Falcon's
    private static function definePaths(string $runtimeFolder, string $pluginFolder) : void{
        $paths = [
            'PATH' => $runtimeFolder . '/',
            'RESOURCE_PATH' => $runtimeFolder . '/resources/',
            'DATA' => str_replace('\\', '/', (string) getcwd()) . '/',
            'PLUGIN_PATH' => $pluginFolder . '/plugins/',
            'LOCALE_DATA_PATH' => $runtimeFolder . '/resources/locale/',
        ];
        foreach($paths as $name => $path){
            if(!defined('pocketmine\\' . $name)){
                define('pocketmine\\' . $name, $path);
            }
        }
        Filesystem::addCleanedPath(\pocketmine\PLUGIN_PATH, Filesystem::CLEAN_PATH_PLUGINS_PREFIX);
    }

    public static function stop() : void{
        self::$plugins?->disablePlugins();
        if(self::$plugins !== null){
            $server = Server::getInstance();
            try{
                $server->getAsyncPool()->shutdown();
                // the threads plugins left running stop with the server, as PocketMine-MP's do
                ThreadManager::getInstance()->stopAll();
            }catch(\Throwable $e){
                Log::critical('Could not stop the threads: ' . $e->getMessage());
            }
            self::$logger?->flush();
        }
        self::$plugins = null;
    }

    public static function plugins() : ?PluginManager{
        return self::$plugins;
    }

    public static function logger() : ServerLogger{
        return self::$logger ??= new ServerLogger();
    }
}
