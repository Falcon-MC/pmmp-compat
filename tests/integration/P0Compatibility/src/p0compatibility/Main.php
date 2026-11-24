<?php

declare(strict_types=1);

namespace p0compatibility;

use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\inventory\BrewingStandInventory;
use pocketmine\block\tile\BrewingStand;
use pocketmine\data\java\GameModeIdMap;
use pocketmine\entity\object\ItemEntity;
use pocketmine\event\entity\EntityCombustByBlockEvent;
use pocketmine\event\entity\EntityDamageByBlockEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityItemPickupEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\item\Item;
use pocketmine\item\LegacyStringToItemParser;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Server;

final class Main extends PluginBase implements Listener{
    private ?ItemEntity $drop = null;
    private int $pickupAttempts = 0;
    /** @var array<string, array{Vector3, int, float}> */
    private array $hazards = [];
    /** @var array<string, \pocketmine\entity\Entity> */
    private array $hazardVictims = [];
    private bool $lavaCombustion = false;

    protected function onEnable() : void{
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask($this->checkServer(...)), 3);
    }

    private function checkServer() : void{
        $server = $this->getServer();
        $properties = $server->getConfigGroup();
        self::expect($server === Server::getInstance(), 'server singleton');
        self::expect($server->getMotd() === 'P0 Compatibility Fixture' && $server->getMaxPlayers() === 19,
            'native server identity and capacity');
        self::expect($server->getPort() !== 19132 && $server->getPortV6() === $server->getPort() + 1,
            'configured non-default ports');
        self::expect($server->getViewDistance() === 11 && $server->getAllowedViewDistance(20) === 11,
            'configured view distance');
        self::expect(!$server->getOnlineMode() && !$server->requiresAuthentication(), 'configured offline mode');
        self::expect($server->getGamemode() === GameMode::CREATIVE && $server->getForceGamemode(),
            'configured default and forced game mode');
        self::expect($properties->getConfigString('server-name') === $server->getMotd() &&
            $properties->getConfigInt('max-players') === $server->getMaxPlayers(), 'shared config group');
        self::expect($server->getPluginManager()->getPlugin('P0Compatibility') === $this &&
            $server->getCommandMap()->getCommand('tell') !== null, 'plugin and command mapping');
        self::expect($server->getTick() >= 3 && $server->getTicksPerSecond() > 0, 'live scheduler, tick and TPS');

        $map = GameModeIdMap::getInstance();
        foreach([GameMode::SURVIVAL, GameMode::CREATIVE, GameMode::ADVENTURE, GameMode::SPECTATOR] as $id => $mode){
            self::expect($map->toId($mode) === $id && $map->fromId($id) === $mode, 'saved game mode ID');
        }
        self::expect($map->fromId(-1) === null, 'unknown game mode ID');

        $worlds = $server->getWorldManager();
        $world = $worlds->getDefaultWorld();
        $spawn = $world->getSpawnLocation();
        self::expect($worlds->getWorldByName($world->getFolderName()) === $world &&
            $world->getDisplayName() !== '' && $spawn->getWorld() === $world, 'world identity and native spawn');
        $chunk = $world->getChunk($spawn->getFloorX() >> 4, $spawn->getFloorZ() >> 4);
        self::expect($chunk !== null, 'native spawn chunk');
        $position = $spawn->add(0, 3, 0);
        $previous = $world->getBlock($position);
        $stone = VanillaBlocks::STONE();
        $world->setBlock($position, $stone);
        try{
            $placed = $world->getBlockAt($position->getFloorX(), $position->getFloorY(), $position->getFloorZ());
            self::expect($placed->isSameState($stone) && $placed->getTypeId() === $stone->getTypeId() &&
                $placed->getName() === 'Stone' && $placed->getPosition()->getWorld() === $world, 'native block state');
            $world->setBlock($position, VanillaBlocks::BREWING_STAND());
            $tile = $world->getTile($position);
            $inventory = $tile instanceof BrewingStand ? $tile->getInventory() : null;
            self::expect($inventory instanceof BrewingStandInventory && $inventory->fromNativeSlot(0) === BrewingStandInventory::SLOT_BOTTLE_LEFT,
                'native brewing inventory exposes PocketMine-MP slots');
            $inventory->setItem(BrewingStandInventory::SLOT_INGREDIENT, VanillaItems::NETHER_WART());
            $inventory->setItem(BrewingStandInventory::SLOT_BOTTLE_LEFT, VanillaItems::GLASS_BOTTLE());
            $readNative = fn(int $slot) => falcon_level_container_item($world->getHandle(), $position->getFloorX(),
                $position->getFloorY(), $position->getFloorZ(), $slot);
            self::expect(($readNative(3)['id'] ?? null) === 'minecraft:nether_wart' &&
                ($readNative(0)['id'] ?? null) === 'minecraft:glass_bottle', 'brewing writes the native ingredient and bottle slots');
            $inventory->clearAll();
        }finally{
            $world->setBlockAt($position->getFloorX(), $position->getFloorY(), $position->getFloorZ(), $previous);
        }
        $originalTime = $world->getTime();
        $world->setTime(1234);
        self::expect($world->getTime() === 1234, 'native world time');
        $world->setTime($originalTime);
        $this->getLogger()->info('server and world compatibility passed');
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player) : void{
            $server = $this->getServer();
            self::expect($player->isOnline() && $player->spawned && $player->getUniqueId()->toString() !== '' &&
                $player->getXuid() === '' && !$player->isOp(), 'live offline-mode session identity');
            self::expect($server->getPlayerExact('vISITOR') === $player && $server->getPlayerByPrefix('VIS') === $player &&
                in_array($player, $server->getOnlinePlayers(), true), 'online player lookups');
            self::expect($server->broadcastMessage('fixture broadcast', [$player]) === 1,
                'targeted chat broadcast');
            self::expect($player->getNetworkSession()->getPlayer() === $player &&
                $player->getLocation()->getWorld() === $player->getWorld(), 'live network and position');
            $world = $player->getWorld();
            $position = $player->getPosition();
            self::expect($world->getEntity($player->getId()) === $player &&
                in_array($player, $world->getEntities(), true), 'native entity wrapper identity');
            $nearby = new AxisAlignedBB($position->x - 2, $position->y - 2, $position->z - 2,
                $position->x + 2, $position->y + 2, $position->z + 2);
            self::expect(in_array($player, $world->getNearbyEntities($nearby), true), 'native entity spatial query');

            $sword = VanillaItems::DIAMOND_SWORD()->setCustomName('P0 blade')->setLore(['First', 'Second']);
            $sword->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 3));
            $sword->setDamage(17);
            $sword->getNamedTag()->setString('fixture-mark', 'live tag mutation');
            self::expect($sword->equalsExact(Item::nbtDeserialize($sword->nbtSerialize(8))) &&
                $sword->nbtSerialize(8)->getByte('Slot') === 8, 'item metadata saved through NBT');
            $parsed = StringToItemParser::getInstance()->parse('diamond_sword');
            $legacy = LegacyStringToItemParser::getInstance()->parse('276:17');
            self::expect($parsed?->getTypeId() === $sword->getTypeId() && $legacy->getDamage() === 17,
                'modern and legacy item parser');
            $inventory = $player->getInventory();
            $previous = $inventory->getItem(8);
            $inventory->setItem(8, $sword);
            try{
                $restored = $inventory->getItem(8);
                self::expect($restored->equalsExact($sword) &&
                    $restored->getNamedTag()->getString('fixture-mark') === 'live tag mutation',
                    'item metadata and live NBT edit cross native inventory');
                $inventory->clear(8);
                self::expect(falcon_player_inventory_item($player->getHandle(), 8)['count'] === 0,
                    'clearing a slot stores canonical native air');
            }finally{
                $inventory->setItem(8, $previous);
            }
            $this->getLogger()->info('player state compatibility passed');
            if(getenv('PMMP_COMPAT_TEST_NATIVE_ITEM_ENTITY') === '1'){
                $this->checkItemEntity($player);
                $this->checkHazards($player);
            }
        }), 2);
    }

    private function checkItemEntity(Player $player) : void{
        $world = $player->getWorld();
        $position = $player->getPosition();
        $item = VanillaItems::DIAMOND()->setCount(2)->setCustomName('P0 native drop');
        $motion = new Vector3(0.25, 0.125, -0.25);
        self::expect($world->dropItem($position, VanillaItems::AIR()) === null, 'empty drop');
        $drop = $world->dropItem($position->add(7, 1, 0), $item, $motion, 53);
        self::expect($drop instanceof ItemEntity && $drop->getItem()->equalsExact($item) &&
            $drop->getMotion()->equals($motion) && $drop->getPickupDelay() === 53, 'returned live drop data');
        self::expect($drop->getWorld() === $world && $world->getEntity($drop->getId()) === $drop &&
            ($world->getEntities()[$drop->getId()] ?? null) === $drop, 'drop wrapper identity and world');
        $drop->flagForDespawn();
        self::expect($drop->isClosed() && $world->getEntity($drop->getId()) === null, 'drop removed from native world');

        $world->setBlockAt($position->getFloorX(), $position->getFloorY() - 1, $position->getFloorZ(), VanillaBlocks::STONE());
        $this->drop = $world->dropItem($position->add(0, 0.75, 0), $item, Vector3::zero(), 20);
        self::expect($this->drop !== null, 'spawned item for delayed pickup');
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $item) : void{
            self::expect($this->pickupAttempts === 0 && !$player->getInventory()->contains($item) &&
                $this->drop->getPickupDelay() > 0, 'pickup delay prevents collection');
            $this->drop->setPickupDelay(0);
            self::expect($this->drop->getPickupDelay() === 0, 'pickup delay updated on native actor');
        }), 5);
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $item) : void{
            self::expect($this->pickupAttempts === 2 && $this->drop->isClosed() &&
                $player->getInventory()->contains($item), 'cancelled pickup retries and eventually collects once');
            $this->getLogger()->info('native dropped item lifecycle passed');
        }), 15);
    }

    public function onPickup(EntityItemPickupEvent $event) : void{
        if($event->getOrigin() !== $this->drop){
            return;
        }
        self::expect($this->drop->getItem()->equalsExact($event->getItem()) && !$this->drop->isClosed(),
            'pickup delivers the live origin wrapper');
        if(++$this->pickupAttempts === 1){
            $event->cancel();
        }
    }

    private function checkHazards(Player $player) : void{
        $world = $player->getWorld();
        $start = $player->getPosition()->floor()->add(3, 0, 5);
        $server = $this->getServer();
        $blocks = [
            ['minecraft:campfire', '{"extinguished":false}', EntityDamageEvent::CAUSE_FIRE, 1.0],
            ['minecraft:sweet_berry_bush', '{"growth":3}', EntityDamageEvent::CAUSE_CONTACT, 1.0],
            ['minecraft:cauldron', '{"cauldron_liquid":"lava","fill_level":6}', EntityDamageEvent::CAUSE_LAVA, 4.0],
        ];
        foreach($blocks as $index => [$identifier, $states, $cause, $damage]){
            $position = $start->add($index * 3, 0, 0);
            $world->getBlock($position);
            $world->setBlock($position->subtract(0, 1, 0), VanillaBlocks::STONE());
            // Keep each small mob within the hazard cell while native ticks run.
            foreach([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$x, $z]){
                $world->setBlock($position->add($x, 0, $z), VanillaBlocks::STONE());
            }
            $world->setBlock($position->add(0, 1, 0), VanillaBlocks::AIR());
            $world->setBlock($position, Block::fromFalcon($identifier, $states));
            self::expect($world->getBlock($position)->getFalconIdentifier() === $identifier, "placed hazard $identifier");
            $this->hazards[$identifier] = [$position, $cause, $damage];
            $at = $position->add(0.5, 0.5, 0.5);
            self::expect($server->dispatchCommand($server->getConsoleSender(), "summon chicken {$at->x} {$at->y} {$at->z}"),
                "summoned a victim for $identifier");
        }
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() : void{
            foreach($this->hazards as $identifier => $_){
                $victim = $this->hazardVictims[$identifier] ?? null;
                self::expect($victim !== null && $victim->getHealth() === (float) $victim->getMaxHealth(),
                    "damage listener preserved health at $identifier");
            }
            self::expect($this->lavaCombustion && !$this->hazardVictims['minecraft:cauldron']->isOnFire(),
                'cancelling cauldron combustion prevents fire');
            $this->getLogger()->info('native block damage and combustion passed');
        }), 35);
    }

    public function onBlockDamage(EntityDamageByBlockEvent $event) : void{
        $block = $event->getDamager();
        $identifier = $block->getFalconIdentifier();
        if(!isset($this->hazards[$identifier])){
            return;
        }
        [$position, $cause, $damage] = $this->hazards[$identifier];
        if(!$block->getPosition()->equals($position)){
            return;
        }
        self::expect($event->getCause() === $cause && $event->getBaseDamage() === $damage, "damage payload for $identifier");
        $this->hazardVictims[$identifier] = $event->getEntity();
        $event->cancel();
    }

    public function onCombustion(EntityCombustByBlockEvent $event) : void{
        if($event->getCombuster()->getFalconIdentifier() === 'minecraft:cauldron'){
            self::expect($event->getDuration() === 8, 'cauldron combustion payload');
            $this->lavaCombustion = true;
            $event->cancel();
        }
    }

    private static function expect(bool $condition, string $check) : void{
        if(!$condition){
            throw new \RuntimeException("P0 fixture failed: $check");
        }
    }
}
