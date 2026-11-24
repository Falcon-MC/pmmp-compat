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
use pocketmine\crafting\CraftingManager;
use pocketmine\crafting\CraftingManagerFromDataHelper;
use pocketmine\lang\Language;
use pocketmine\lang\Translatable;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
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
    private \pocketmine\snooze\SleeperHandler $tickSleeper;
    private WorldManager $worldManager;
    private ?CraftingManager $craftingManager = null;
    private int $tickCounter = 0;
    private ?\pocketmine\network\query\QueryInfo $queryInfo = null;
    private ?\pmmpcompat\QuerySocket $querySocket = null;
    private ?\pocketmine\updater\UpdateChecker $updater = null;
    private ?MemoryManager $memoryManager = null;

    public static function getInstance() : Server{
        if(self::$instance === null){
            throw new \LogicException("the server is not started yet");
        }
        return self::$instance;
    }

    public function getCraftingManager() : CraftingManager{
        $this->craftingManager ??= CraftingManagerFromDataHelper::make(BEDROCK_DATA_PATH . "recipes");
        $this->craftingManager->publishPluginRecipes();
        return $this->craftingManager;
    }

    /** @internal the runtime makes the one server */
    public function __construct(
        private ServerLogger $logger,
        private \pocketmine\thread\ThreadSafeClassLoader $loader,
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
        $this->tickSleeper = new \pocketmine\snooze\SleeperHandler();
        // two workers, PocketMine-MP's "auto" on a small machine; each task runs on a thread of its own
        $this->asyncPool = new AsyncPool(2, 256, $loader, $logger, $this->tickSleeper);
        $this->worldManager = new WorldManager();
        $this->startTime = microtime(true);
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
        if(++$this->tickCounter === 1){
            $this->startQueryListener();
        }
        $this->pluginManager->tickSchedulers($this->tickCounter);
        $this->worldManager->tickScheduledBlockUpdates($this->tickCounter);
        // what other threads asked for: their notifications, then the async tasks done, then their log lines
        $this->tickSleeper->processNotifications();
        $this->network?->tick();
        $this->asyncPool->collectTasks();
        $this->resourcePackManager?->publish();
        $this->logger->flush();
        $this->getMemoryManager()->check();
        if($this->tickCounter === 1 || $this->tickCounter % 20 === 0){
            $query = new \pocketmine\event\server\QueryRegenerateEvent(new \pocketmine\network\query\QueryInfo($this));
            $query->call();
            $this->queryInfo = $query->getQueryInfo();
        }
        $this->querySocket?->tick($this->getQueryInformation());
        if($this->tickCounter % 1200 === 0){
            \pmmpcompat\Entities::forgetClosed();
        }
    }

    private function startQueryListener() : void{
        $config = $this->getConfigGroup();
        if($this->querySocket !== null || !$config->getConfigBool('enable-query', false)){
            return;
        }
        try{
            $this->querySocket = new \pmmpcompat\QuerySocket(
                $config->getConfigString('query-ip', '0.0.0.0') ?: '0.0.0.0',
                $config->getConfigInt('query-port', $this->getPort())
            );
        }catch(\RuntimeException|\InvalidArgumentException $error){
            $this->logger->warning($error->getMessage() . '. Set query-port to a free UDP port when using RakNet.');
        }
    }

    /** @internal */
    public function stopQueryListener() : void{
        $this->querySocket?->close();
        $this->querySocket = null;
    }

    public function getQueryInformation() : \pocketmine\network\query\QueryInfo{
        return $this->queryInfo ??= new \pocketmine\network\query\QueryInfo($this);
    }

    public function getUpdater() : \pocketmine\updater\UpdateChecker{
        return $this->updater ??= new \pocketmine\updater\UpdateChecker($this);
    }

    public function getMemoryManager() : MemoryManager{
        return $this->memoryManager ??= new MemoryManager($this->getConfigGroup());
    }

    public function getTick() : int{
        return $this->tickCounter;
    }

    /** Falcon runs at the game's 20 ticks a second */
    public function getTicksPerSecond() : float{
        return round(falcon_server_tps()[0], 2);
    }

    public function getTicksPerSecondAverage() : float{
        return $this->getTicksPerSecond();
    }

    /** how much of a tick's 50 ms the last tick took, in percent */
    public function getTickUsage() : float{
        return round(falcon_server_tps()[1] / 50 * 100, 2);
    }

    public function getTickUsageAverage() : float{
        return $this->getTickUsage();
    }

    private ?\pocketmine\network\Network $network = null;
    private ?ServerConfigGroup $configGroup = null;

    /** the network interfaces plugins add, an RCON server of their own; players connect through Falcon */
    public function getNetwork() : \pocketmine\network\Network{
        return $this->network ??= new \pocketmine\network\Network();
    }

    /**
     * pocketmine.yml beside the PocketMine-MP plugins, and Falcon's own server.properties, which have the same keys
     * for what both servers know
     */
    public function getConfigGroup() : ServerConfigGroup{
        if($this->configGroup === null){
            $pocketmineYml = new \pocketmine\utils\Config(dirname(rtrim($this->pluginPath, "/\\")) . DIRECTORY_SEPARATOR . "pocketmine.yml", \pocketmine\utils\Config::YAML, []);
            $properties = $this->dataPath . "server.properties";
            $serverProperties = new \pocketmine\utils\Config(is_file($properties) ? $properties : $pocketmineYml->getPath() . ".properties", \pocketmine\utils\Config::PROPERTIES, []);
            $this->configGroup = new ServerConfigGroup($pocketmineYml, $serverProperties);
        }
        return $this->configGroup;
    }

    /** the loader of the runtime's and plugins' classes, which threads register too */
    public function getLoader() : \pocketmine\thread\ThreadSafeClassLoader{
        return $this->loader;
    }

    /** where threads wake the server: a notifier's handler runs on the next tick */
    public function getTickSleeper() : \pocketmine\snooze\SleeperHandler{
        return $this->tickSleeper;
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

    public function getLogger() : \pocketmine\thread\log\AttachableThreadSafeLogger{
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
     * @deprecated as in PocketMine-MP: NetworkBroadcastUtils::broadcastPackets()
     * @param Player[]            $players
     * @param ClientboundPacket[] $packets
     */
    public function broadcastPackets(array $players, array $packets) : bool{
        return NetworkBroadcastUtils::broadcastPackets($players, $packets);
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
        foreach($this->subscriptions[$channelId] ?? [] as $id => $subscriber){
            $subscribers[$id] = $subscriber;
        }
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

    private ?\pocketmine\permission\BanList $nameBans = null;
    private ?\pocketmine\permission\BanList $ipBans = null;
    private ?\pocketmine\resourcepacks\ResourcePackManager $resourcePackManager = null;
    private ?\Ramsey\Uuid\UuidInterface $serverId = null;
    private float $startTime = 0.0;

    public function getNameBans() : \pocketmine\permission\BanList{
        return $this->nameBans ??= new \pocketmine\permission\BanList(false);
    }

    public function getIPBans() : \pocketmine\permission\BanList{
        return $this->ipBans ??= new \pocketmine\permission\BanList(true);
    }

    public function getResourcePackManager() : \pocketmine\resourcepacks\ResourcePackManager{
        return $this->resourcePackManager ??= new \pocketmine\resourcepacks\ResourcePackManager($this->dataPath . "resource_packs" . DIRECTORY_SEPARATOR);
    }

    /** an id for this server, the same from one start to the next: kept in the data folder */
    public function getServerUniqueId() : \Ramsey\Uuid\UuidInterface{
        if($this->serverId === null){
            $file = $this->pluginPath . DIRECTORY_SEPARATOR . ".pmmp-compat-server-id";
            $saved = @file_get_contents($file);
            if($saved !== false && \Ramsey\Uuid\Uuid::isValid(trim($saved))){
                $this->serverId = \Ramsey\Uuid\Uuid::fromString(trim($saved));
            }else{
                $this->serverId = \Ramsey\Uuid\Uuid::uuid4();
                @file_put_contents($file, $this->serverId->toString());
            }
        }
        return $this->serverId;
    }

    public function getMaxPlayers() : int{
        return falcon_server_max_players();
    }

    public function getMotd() : string{
        return falcon_server_motd();
    }

    public function getOnlineMode() : bool{
        return true;
    }

    public function requiresAuthentication() : bool{
        return true;
    }

    public function getPort() : int{
        return $this->getConfigGroup()->getConfigInt('server-port', 19132);
    }

    public function getPortV6() : int{
        return 19133;
    }

    public function getIp() : string{
        return "0.0.0.0";
    }

    public function getIpV6() : string{
        return "::";
    }

    public function getViewDistance() : int{
        return 8;
    }

    public function getAllowedViewDistance(int $distance) : int{
        return max(2, min($distance, $this->getViewDistance()));
    }

    public function getGamemode() : \pocketmine\player\GameMode{
        return \pocketmine\player\GameMode::SURVIVAL();
    }

    public function getForceGamemode() : bool{
        return false;
    }

    /** one of World's DIFFICULTY_ numbers */
    public function getDifficulty() : int{
        return falcon_server_difficulty();
    }

    public function hasWhitelist() : bool{
        return falcon_server_allow_list_enabled();
    }

    public function isWhitelisted(string $name) : bool{
        return !$this->hasWhitelist() || $this->isOp($name)
            || in_array(strtolower($name), array_map('strtolower', falcon_server_allow_list()), true);
    }

    public function addWhitelist(string $name) : void{
        falcon_server_allow($name, true);
    }

    public function removeWhitelist(string $name) : void{
        falcon_server_allow($name, false);
    }

    public function getWhitelisted() : \pocketmine\utils\Config{
        $config = new \pocketmine\utils\Config($this->dataPath . "white-list.txt", \pocketmine\utils\Config::ENUM);
        $config->setAll(array_fill_keys(array_map('strtolower', falcon_server_allow_list()), true));
        return $config;
    }

    public function addOp(string $name) : void{
        $player = $this->getPlayerExact($name);
        if($player !== null){
            falcon_player_set_operator($player->getHandle(), true);
        }else{
            $this->dispatchCommand($this->console, "op " . $name);
        }
    }

    public function removeOp(string $name) : void{
        $player = $this->getPlayerExact($name);
        if($player !== null){
            falcon_player_set_operator($player->getHandle(), false);
        }else{
            $this->dispatchCommand($this->console, "deop " . $name);
        }
    }

    public function isHardcore() : bool{
        return false;
    }

    public function getStartTime() : float{
        return $this->startTime;
    }

    public function getPlayerByUUID(\Ramsey\Uuid\UuidInterface $uuid) : ?Player{
        foreach(Players::online() as $player){
            if($player->getUniqueId()->equals($uuid)){
                return $player;
            }
        }
        return null;
    }

    public function getPlayerByRawUUID(string $rawUUID) : ?Player{
        try{
            return $this->getPlayerByUUID(\Ramsey\Uuid\Uuid::fromBytes($rawUUID));
        }catch(\InvalidArgumentException){
            return null;
        }
    }

    public function shouldSavePlayerData() : bool{
        return true;
    }

    public function hasOfflinePlayerData(string $name) : bool{
        return $this->getOfflinePlayerData($name) !== null;
    }

    public function getOfflinePlayerData(string $name) : ?\pocketmine\nbt\tag\CompoundTag{
        return \pmmpcompat\PlayerHistory::offline($name);
    }

    public function getOfflinePlayer(string $name) : Player|\pocketmine\player\OfflinePlayer|null{
        return $this->getPlayerExact($name) ?? new \pocketmine\player\OfflinePlayer($name, $this->getOfflinePlayerData($name));
    }

    /**
     * @param Player[]|null $recipients
     */
    public function broadcastTip(string $tip, ?array $recipients = null) : int{
        $recipients ??= $this->playersOf(self::BROADCAST_CHANNEL_USERS);
        foreach($recipients as $player){
            $player->sendTip($tip);
        }
        return count($recipients);
    }

    /**
     * @param Player[]|null $recipients
     */
    public function broadcastPopup(string $popup, ?array $recipients = null) : int{
        $recipients ??= $this->playersOf(self::BROADCAST_CHANNEL_USERS);
        foreach($recipients as $player){
            $player->sendPopup($popup);
        }
        return count($recipients);
    }

    /**
     * @param Player[]|null $recipients
     */
    public function broadcastTitle(string $title, string $subtitle = "", int $fadeIn = -1, int $stay = -1, int $fadeOut = -1, ?array $recipients = null) : int{
        $recipients ??= $this->playersOf(self::BROADCAST_CHANNEL_USERS);
        foreach($recipients as $player){
            $player->sendTitle($title, $subtitle, $fadeIn, $stay, $fadeOut);
        }
        return count($recipients);
    }

    /** @return Player[] */
    private function playersOf(string $channel) : array{
        return array_filter($this->getBroadcastChannelSubscribers($channel), static fn($sender) => $sender instanceof Player);
    }

    /** @var array<string, array<int, CommandSender>> what plugins subscribed, by channel */
    private array $subscriptions = [];

    public function subscribeToBroadcastChannel(string $channelId, CommandSender $subscriber) : void{
        $this->subscriptions[$channelId][spl_object_id($subscriber)] = $subscriber;
    }

    public function unsubscribeFromBroadcastChannel(string $channelId, CommandSender $subscriber) : void{
        unset($this->subscriptions[$channelId][spl_object_id($subscriber)]);
    }

    public function unsubscribeFromAllBroadcastChannels(CommandSender $subscriber) : void{
        foreach($this->subscriptions as $channelId => $subscribers){
            unset($this->subscriptions[$channelId][spl_object_id($subscriber)]);
        }
    }

    /** stops the server, as /stop does */
    public function shutdown() : void{
        falcon_server_shutdown();
    }

    public function forceShutdown() : void{
        $this->shutdown();
    }

    public function isLanguageForced() : bool{
        return false;
    }

    public function getFilePath() : string{
        return $this->dataPath;
    }

    public function getResourcePath() : string{
        return $this->dataPath;
    }
}
