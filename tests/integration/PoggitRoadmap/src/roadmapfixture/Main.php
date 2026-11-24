<?php

declare(strict_types=1);

namespace roadmapfixture;

use BlockHorizons\InvSee\libs\muqsit\invmenu\InvMenuHandler;
use czechpmdevs\buildertools\async\AsyncQueue;
use czechpmdevs\buildertools\async\schematics\SchematicCreateTask;
use czechpmdevs\buildertools\blockstorage\BlockArray;
use czechpmdevs\buildertools\schematics\format\BuilderToolsSchematic;
use czechpmdevs\buildertools\session\SessionManager;
use onebone\economyapi\EconomyAPI;
use pmmpcompat\PluginServices;
use pocketmine\block\VanillaBlocks;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\EntityFactory;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\item\VanillaItems;
use pocketmine\world\Position;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginOwned;
use pocketmine\scheduler\ClosureTask;
use room17\SkyBlock\SkyBlock;
use slapper\SlapperInterface;

final class Main extends PluginBase implements Listener{
    private bool $clicked = false;
    private ?Player $npcViewer = null;

    protected function onEnable() : void{
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $command = $this->getServer()->getPluginCommand('roadmapclick');
        self::check($command instanceof PluginOwned && $command->getOwningPlugin() === $this, 'plugin command owner');
        self::check(PluginServices::register($this, 'roadmap.php', static fn(string $input) => $input === 'from-native' ? 'from-php' : ''), 'register PHP service');
        self::check(!PluginServices::register($this, 'roadmap.php', static fn(string $input) => $input), 'duplicate PHP service');
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() : void{
            self::check(PluginServices::exists('roadmap.native') && PluginServices::provider('roadmap.native') === 'RoadmapProbe', 'find native service');
            self::check(PluginServices::call('roadmap.native', 'from-php') === 'from-native', 'PHP to native service');
            self::check(PluginServices::provider('roadmap.php') === $this->getName(), 'PHP owner');
            self::check(PluginServices::call('roadmap.missing') === null, 'missing service');
            $this->getLogger()->info('PluginOwned and PHP-to-native service passed');
        }), 10);
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() : void{
            $this->getServer()->getPluginManager()->disablePlugin($this);
        }), 130);
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        if($player->getName() !== 'RoadRunner'){
            return;
        }

        $actions = ['BuilderTools' => 'builderTools', 'MultiWorld' => 'worlds', 'CustomShopUI' => 'shop',
            'SkyBlock' => 'skyBlock', 'Slapper' => 'slapper', 'VanishV2' => 'vanish', 'InvSee' => 'invSee'];
        foreach($actions as $plugin => $exercise){
            if($this->getServer()->getPluginManager()->getPlugin($plugin) !== null){
                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $exercise) : void{
                    $player->setOp(true);
                    $this->{$exercise}($player);
                }), $exercise === 'invSee' ? 40 : 6);
            }
        }
    }

    private function builderTools(Player $player) : void{
        $world = $player->getWorld();
        $y = min($world->getMaxY() - 1, $player->getPosition()->getFloorY() + 5);
        $server = $this->getServer();
        $selection = SessionManager::getInstance()->getSession($player)->getSelectionHolder();
        $selection->handleWandAxeBlockBreak(new Position(0, $y, 0, $world));
        $selection->handleWandAxeBlockClick(new Position(31, $y, 31, $world));
        self::check($server->getCommandMap()->getCommand('/set') !== null, 'BuilderTools alias registered');
        $server->dispatchCommand($player, '/set stone');
        $schematic = new BlockArray();
        for($x = 0; $x < 32; ++$x){
            for($z = 0; $z < 32; ++$z){
                $block = $world->getBlockAt($x, $y, $z);
                self::check($block->getTypeId() === VanillaBlocks::STONE()->getTypeId(), "edit at $x,$z");
                $schematic->addBlockAt($x, 0, $z, $block->getStateId());
            }
        }
        $server->dispatchCommand($player, '/undo');
        self::check($world->getBlockAt(31, $y, 31)->getTypeId() !== VanillaBlocks::STONE()->getTypeId(), 'undo across chunks');
        $path = $this->getDataFolder() . 'road.btschematics';
        $expected = (new BuilderToolsSchematic())->save($schematic);
        AsyncQueue::submitTask(new SchematicCreateTask($path, BuilderToolsSchematic::class, $schematic),
            function(SchematicCreateTask $task) use ($path, $expected) : void{
                self::check($task->error === null && file_get_contents($path) === $expected, 'async schematic matches edited region');
                $this->getLogger()->info('BuilderTools 1024-block edit, undo and async schematic passed');
            });
    }

    private function worlds(Player $player) : void{
        $server = $this->getServer();
        $manager = $server->getWorldManager();
        $console = $server->getConsoleSender();
        $generator = getenv('ROADMAP_MULTI_GENERATOR') ?: 'flat';
        $server->dispatchCommand($console, 'mw create RoadMulti 123 ' . $generator);
        $server->dispatchCommand($console, 'worlds create RoadWorld flat');
        $multi = $manager->getWorldByName('RoadMulti');
        $world = $manager->getWorldByName('RoadWorld');
        self::check($multi !== null && $world !== null && $world->getBlockAt(0, 0, 0)->getTypeId() === VanillaBlocks::BEDROCK()->getTypeId(), 'both real commands generate worlds, including flat terrain');
        $multiSpawn = $multi->getSpawnLocation();
        $multi->loadChunk($multiSpawn->getFloorX() >> 4, $multiSpawn->getFloorZ() >> 4);
        self::check($multi->getHighestBlockAt($multiSpawn->getFloorX(), $multiSpawn->getFloorZ()) !== null,
            'MultiWorld generator wrote terrain');
        $server->dispatchCommand($player, 'worlds teleport RoadWorld');
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $server, $manager, $multi, $world) : void{
            self::check($player->getWorld() === $world, 'Worlds teleported a player');
            $server->dispatchCommand($player, 'mw teleport RoadMulti');
            $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $manager, $multi, $world) : void{
                self::check($player->getWorld() === $multi, 'MultiWorld teleported a player');
                self::check($manager->unloadWorld($world), 'unload world');
                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($manager, $world) : void{
                    self::check(!$world->isLoaded(), 'retained wrapper observed deferred unload');
                    try{
                        $world->getBlockAt(0, 0, 0);
                        throw new \RuntimeException('retained wrapper accepted native block read after unload');
                    }catch(\LogicException){
                        // Expected before the first access to a native handle.
                    }
                    self::check(!$manager->isWorldLoaded('RoadWorld') && $manager->isWorldGenerated('RoadWorld'), 'unloaded world persisted');
                    self::check($manager->loadWorld('RoadWorld') && $manager->getWorldByName('RoadWorld') !== $world, 'reload creates a live handle');
                    $this->getLogger()->info('MultiWorld and Worlds generate, teleport and reload passed');
                }), 5);
            }), 8);
        }), 8);
    }

    private function shop(Player $player) : void{
        $economy = EconomyAPI::getInstance();
        $economy->setMoney($player, 100);
        $this->getServer()->dispatchCommand($player, 'shop');
        if(getenv('ROADMAP_REAL_CLIENT') !== false){
            $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $economy) : void{
                $this->checkPurchase($player, $economy);
            }), 40);
        }else{
            $this->respond($player, '0');
            $this->respond($player, '0');
            $this->respond($player, '[null,false,"2"]');
            $this->checkPurchase($player, $economy);
        }
    }

    private function checkPurchase(Player $player, EconomyAPI $economy) : void{
        self::check((float) $economy->myMoney($player) === 80.0, 'purchase debited 20');
        self::check($player->getInventory()->contains(VanillaItems::APPLE()->setCount(2)), 'purchased two apples');
        $this->getLogger()->info('CustomShopUI category, item and purchase forms passed');
        $player->sendMessage('roadmap-shop-purchased');
    }

    private function respond(Player $player, string $json) : void{
        $pending = (new \ReflectionProperty(Player::class, 'forms'))->getValue($player);
        self::check(count($pending) === 1, 'one pending form for ' . $json);
        $player->onFalconFormResponse(array_key_first($pending), $json, false);
    }

    private function skyBlock(Player $player) : void{
        $plugin = $this->getServer()->getPluginManager()->getPlugin('SkyBlock');
        self::check($plugin instanceof SkyBlock, 'SkyBlock enabled');
        $identityFile = $this->getDataFolder() . 'island-name';
        $session = $plugin->getSessionManager()->getSession($player);
        $restarted = is_file($identityFile);
        if($restarted){
            self::check($session->hasIsland(), 'island membership survived restart');
            $island = $session->getIsland();
            self::check($island->getWorld()->getFolderName() === file_get_contents($identityFile), 'same island world reloaded');
            self::check($player->teleport($island->getSpawnLocation()), 'player can revisit reloaded island');
        }else{
            $this->getServer()->dispatchCommand($player, 'island create shelly');
            self::check($session->hasIsland(), 'island created');
            $island = $session->getIsland();
            file_put_contents($identityFile, $island->getWorld()->getFolderName());
        }
        $world = $island->getWorld();
        $world->loadChunk(0, 0);
        self::check($world->getBlockAt(11, 34, 9)->getTypeId() === VanillaBlocks::GRASS()->getTypeId(), 'Shelly generated its own terrain: ' . $world->getBlockAt(11, 34, 9)->getFalconIdentifier());
        self::check($world->getBlockAt(0, 10, 0)->getTypeId() === VanillaBlocks::AIR()->getTypeId(), 'surrounding world is void');
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $world, $restarted) : void{
            self::check($player->getWorld() === $world, 'player joined island');
            $this->getLogger()->info($restarted ? 'SkyBlock reloaded the same Shelly island' : 'SkyBlock generated and joined the Shelly island');
        }), 8);
    }

    private function slapper(Player $player) : void{
        $this->getServer()->dispatchCommand($player, 'slapper create human RoadNPC');
        $npc = null;
        foreach($player->getWorld()->getEntities() as $entity){
            if($entity instanceof SlapperInterface){
                $npc = $entity;
                break;
            }
        }
        self::check($npc instanceof SlapperInterface && $npc->getNameTag() === 'RoadNPC', 'Slapper human spawned');
        $npc->addCommand('roadmapclick');
        if(getenv('ROADMAP_REAL_CLIENT') !== false){
            $this->npcViewer = $player;
            $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($npc) : void{
                $npc->close();
            }), 100);
        }else{
            $npc->attack(new EntityDamageByEntityEvent($player, $npc, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
            self::check($this->clicked && $npc->isAlive(), 'NPC hit intercepted and command invoked');
        }
        $copy = EntityFactory::getInstance()->createFromData($player->getWorld(), $npc->saveNBT());
        self::check($copy instanceof SlapperInterface && $copy->getCommands() === ['roadmapclick'], 'Slapper persisted NPC command');
        $copy->close();
        if($this->npcViewer === null){
            $npc->close();
        }
        $this->getLogger()->info('Slapper NPC, hit command and NBT passed');
    }

    private function vanish(Player $player) : void{
        $viewer = $this->getServer()->getPlayerExact('RoadViewer');
        self::check($viewer !== null && $viewer->canSee($player), 'initial visibility');
        $this->getServer()->dispatchCommand($player, 'vanish');
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $viewer) : void{
            self::check(!$viewer->canSee($player) && !falcon_player_is_spawned_to($player->getHandle(), $viewer->getHandle()), 'hidden from observer');
            $player->spawnTo($viewer);
            self::check(!falcon_player_is_spawned_to($player->getHandle(), $viewer->getHandle()), 'hidden actor cannot respawn');
            $this->getServer()->dispatchCommand($player, 'vanish');
            self::check($viewer->canSee($player), 'visible again');
            $player->getWorld()->loadChunk($player->getPosition()->getFloorX() >> 4, $player->getPosition()->getFloorZ() >> 4);
            $player->spawnTo($viewer);
            self::check(falcon_player_is_spawned_to($player->getHandle(), $viewer->getHandle()), 'actor spawned for viewer');
            $player->despawnFrom($viewer);
            self::check(!falcon_player_is_spawned_to($player->getHandle(), $viewer->getHandle()), 'explicit despawn');
            $this->getLogger()->info('VanishV2 and native player visibility passed');
        }), 27);
    }

    private function invSee(Player $player) : void{
        $target = $this->getServer()->getPlayerExact('RoadViewer');
        self::check($target !== null, 'inventory target connected');
        $target->getInventory()->setItem(2, VanillaItems::DIAMOND()->setCount(3));
        $target->getEnderInventory()->setItem(4, VanillaItems::EMERALD()->setCount(2));
        $this->getServer()->dispatchCommand($player, 'invsee RoadViewer');
        $session = InvMenuHandler::getPlayerManager()->get($player);
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $session) : void{
            self::check($session->getCurrent()?->menu->getInventory()->getItem(2)->equalsExact(VanillaItems::DIAMOND()->setCount(3)) ?? false,
                'InvSee reflects online inventory');
            $player->removeCurrentWindow();
            $this->getServer()->dispatchCommand($player, 'enderinvsee RoadViewer');
            $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $session) : void{
                self::check($session->getCurrent()?->menu->getInventory()->getItem(4)->equalsExact(VanillaItems::EMERALD()->setCount(2)) ?? false,
                    'InvSee reflects online ender inventory');
                $this->getLogger()->info('InvSee player and ender inventory passed');
                $player->sendMessage('roadmap-inventory-ready');
            }), 25);
        }), 25);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
        $this->clicked = true;
        if($this->npcViewer !== null){
            $this->npcViewer->sendMessage('roadmap-npc-hit');
            $this->getLogger()->info('Slapper Bedrock click reached its command');
        }
        return true;
    }

    private static function check(bool $condition, string $failure) : void{
        if(!$condition){
            throw new \RuntimeException($failure);
        }
    }
}
