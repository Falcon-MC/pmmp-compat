<?php

declare(strict_types=1);

namespace pocketmine;

use pmmpcompat\Players;
use pmmpcompat\Runtime;
use pmmpcompat\ServerLogger;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\PluginCommand;
use pocketmine\command\SimpleCommandMap;
use pocketmine\console\ConsoleCommandSender;
use pocketmine\lang\Language;
use pocketmine\lang\Translatable;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use pocketmine\plugin\PluginManager;
use pocketmine\scheduler\AsyncPool;
use pocketmine\world\WorldManager;
use pocketmine\utils\BroadcastLoggerForwarder;

/**
 * The server, as PocketMine-MP plugins see it: the players, the plugins, the commands and the log.
 *
 * It answers from Falcon: the players online are Falcon's, and a command line runs through Falcon's commands when no
 * PocketMine-MP plugin registered it.
 */
class Server{
    public const BROADCAST_CHANNEL_ADMINISTRATIVE = "pocketmine.broadcast.admin";
    public const BROADCAST_CHANNEL_USERS = "pocketmine.broadcast.user";

    private static ?Server $instance = null;

    private PluginManager $pluginManager;
    private SimpleCommandMap $commandMap;
    private Language $language;
    private ConsoleCommandSender $console;
    private BroadcastLoggerForwarder $logForwarder;
    private AsyncPool $asyncPool;
    private WorldManager $worldManager;
    private int $tickCounter = 0;

    public static function getInstance() : Server{
        if(self::$instance === null){
            throw new \LogicException("the server is not started yet");
        }
        return self::$instance;
    }

    /** @internal the runtime makes the one server */
    public function __construct(
        private ServerLogger $logger,
        private string $dataPath,
        private string $pluginPath,
        string $pluginDataPath
    ){
        self::$instance = $this;
        $this->language = new Language(Language::FALLBACK_LANGUAGE);
        $this->console = new ConsoleCommandSender($this, $this->language);
        $this->logForwarder = new BroadcastLoggerForwarder($this, $logger, $this->language);
        $this->commandMap = new SimpleCommandMap($this);
        $this->pluginManager = new PluginManager($this, $pluginDataPath);
        $this->asyncPool = new AsyncPool();
        $this->worldManager = new WorldManager();
    }

    public function getWorldManager() : WorldManager{
        return $this->worldManager;
    }

    /** Falcon's own version, which names the game version it speaks */
    public function getVersion() : string{
        return falcon_server_version();
    }

    /** @internal each of Falcon's ticks: the plugins' schedulers, then the async tasks */
    public function tick() : void{
        ++$this->tickCounter;
        $this->pluginManager->tickSchedulers($this->tickCounter);
        $this->asyncPool->collectTasks();
        if($this->tickCounter % 1200 === 0){
            \pmmpcompat\Entities::forgetClosed();
        }
    }

    public function getTick() : int{
        return $this->tickCounter;
    }

    /** Falcon runs at the game's 20 ticks a second */
    public function getTicksPerSecond() : float{
        return 20.0;
    }

    public function getTicksPerSecondAverage() : float{
        return 20.0;
    }

    public function getAsyncPool() : AsyncPool{
        return $this->asyncPool;
    }

    public function getName() : string{
        return "Falcon";
    }

    public function isRunning() : bool{
        return true;
    }

    /** the PocketMine-MP version this server answers as */
    public function getPocketMineVersion() : string{
        return Runtime::API_VERSION;
    }

    public function getApiVersion() : string{
        return Runtime::API_VERSION;
    }

    public function getDataPath() : string{
        return $this->dataPath;
    }

    public function getPluginPath() : string{
        return $this->pluginPath;
    }

    public function getLogger() : \AttachableLogger{
        return $this->logger;
    }

    public function getLanguage() : Language{
        return $this->language;
    }

    public function getPluginManager() : PluginManager{
        return $this->pluginManager;
    }

    public function getCommandMap() : SimpleCommandMap{
        return $this->commandMap;
    }

    /** @internal what Falcon's console sends as */
    public function getConsoleSender() : ConsoleCommandSender{
        return $this->console;
    }

    /**
     * @return Player[]
     */
    public function getOnlinePlayers() : array{
        return Players::online();
    }

    public function getPlayerExact(string $name) : ?Player{
        $name = strtolower($name);
        foreach(Players::online() as $player){
            if(strtolower($player->getName()) === $name){
                return $player;
            }
        }
        return null;
    }

    /**
     * The online player whose name starts with the given text, the shortest such name when several do.
     */
    public function getPlayerByPrefix(string $name) : ?Player{
        $found = null;
        $name = strtolower($name);
        $delta = PHP_INT_MAX;
        foreach(Players::online() as $player){
            if(stripos($player->getName(), $name) === 0){
                $curDelta = strlen($player->getName()) - strlen($name);
                if($curDelta < $delta){
                    $found = $player;
                    $delta = $curDelta;
                }
                if($curDelta === 0){
                    break;
                }
            }
        }
        return $found;
    }

    public function getPluginCommand(string $name) : ?PluginCommand{
        $command = $this->commandMap->getCommand($name);
        return $command instanceof PluginCommand ? $command : null;
    }

    /**
     * @param CommandSender[]|null $recipients everyone on the user broadcast channel when null
     * @return int how many received it
     */
    public function broadcastMessage(Translatable|string $message, ?array $recipients = null) : int{
        $recipients ??= $this->getBroadcastChannelSubscribers(self::BROADCAST_CHANNEL_USERS);
        foreach($recipients as $recipient){
            $recipient->sendMessage($message);
        }
        return count($recipients);
    }

    /**
     * The players holding the channel's permission, and the console.
     *
     * @return CommandSender[]
     */
    public function getBroadcastChannelSubscribers(string $channelId) : array{
        $subscribers = [];
        $permission = match($channelId){
            self::BROADCAST_CHANNEL_USERS => DefaultPermissionNames::BROADCAST_USER,
            self::BROADCAST_CHANNEL_ADMINISTRATIVE => DefaultPermissionNames::BROADCAST_ADMIN,
            default => $channelId,
        };
        foreach(Players::online() as $player){
            if($player->hasPermission($permission)){
                $subscribers[spl_object_id($player)] = $player;
            }
        }
        $subscribers[spl_object_id($this->logForwarder)] = $this->logForwarder;
        return $subscribers;
    }

    /**
     * Runs a command line as the sender: through the PocketMine-MP plugin that registered it, or through Falcon.
     */
    public function dispatchCommand(CommandSender $sender, string $commandLine, bool $internal = false) : bool{
        if($this->commandMap->dispatch($sender, $commandLine, false)){
            return true;
        }
        if($sender instanceof Player){
            return $sender->isOnline() && falcon_player_execute_command($sender->getHandle(), $commandLine);
        }
        return falcon_execute_command($commandLine);
    }

    /** @internal for Command::broadcastCommandMessage() and the like */
    public function isOp(string $name) : bool{
        return $this->getPlayerExact($name)?->isOp() ?? false;
    }

    /** @return Command[] */
    public function getCommandAliases() : array{
        return [];
    }
}
