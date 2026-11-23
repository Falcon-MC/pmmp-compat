<?php

declare(strict_types=1);

namespace pmmpcompat;

use pmmpcompat\entity\GenericEntity;
use pmmpcompat\entity\GenericLiving;
use pmmpcompat\entity\FalconProjectile;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;
use pocketmine\Server;
use pocketmine\entity\object as objects;
use pocketmine\entity\projectile as projectiles;
use pocketmine\entity\Squid;
use pocketmine\entity\Villager;
use pocketmine\entity\Zombie;

/**
 * One Entity object for each of Falcon's entities, by runtime id; a player's is their Player.
 */
final class Entities{
    /** the PocketMine-MP class of each Falcon entity type that has one */
    private const CLASSES = [
        "minecraft:item" => objects\ItemEntity::class,
        "minecraft:xp_orb" => objects\ExperienceOrb::class,
        "minecraft:falling_block" => objects\FallingBlock::class,
        "minecraft:tnt" => objects\PrimedTNT::class,
        "minecraft:painting" => objects\Painting::class,
        "minecraft:arrow" => projectiles\Arrow::class,
        "minecraft:snowball" => projectiles\Snowball::class,
        "minecraft:egg" => projectiles\Egg::class,
        "minecraft:ender_pearl" => projectiles\EnderPearl::class,
        "minecraft:xp_bottle" => projectiles\ExperienceBottle::class,
        "minecraft:splash_potion" => projectiles\SplashPotion::class,
        "minecraft:lingering_potion" => projectiles\SplashPotion::class,
        "minecraft:thrown_trident" => projectiles\Trident::class,
        "minecraft:fireball" => FalconProjectile::class,
        "minecraft:small_fireball" => FalconProjectile::class,
        "minecraft:dragon_fireball" => FalconProjectile::class,
        "minecraft:wither_skull" => FalconProjectile::class,
        "minecraft:wither_skull_dangerous" => FalconProjectile::class,
        "minecraft:shulker_bullet" => FalconProjectile::class,
        "minecraft:llama_spit" => FalconProjectile::class,
        "minecraft:wind_charge_projectile" => FalconProjectile::class,
        "minecraft:breeze_wind_charge_projectile" => FalconProjectile::class,
        "minecraft:fishing_hook" => FalconProjectile::class,
        "minecraft:zombie" => Zombie::class,
        "minecraft:villager" => Villager::class,
        "minecraft:villager_v2" => Villager::class,
        "minecraft:squid" => Squid::class,
    ];

    /** the identifier a PocketMine-MP entity class stands for, "" for the classes several types share */
    public static function networkTypeOf(string $class) : string{
        static $types = null;
        if($types === null){
            $types = [];
            foreach(self::CLASSES as $identifier => $entityClass){
                $types[$entityClass] ??= $identifier;
            }
            $types[FalconProjectile::class] = "";
        }
        // a plugin's class built on one of PocketMine-MP's mobs looks like that mob
        for($parent = $class; $parent !== false; $parent = get_parent_class($parent)){
            if(isset($types[$parent])){
                return $types[$parent];
            }
        }
        return "";
    }

    /** @var Entity[] by runtime id */
    private static array $byId = [];

    private function __construct(){
    }

    /** the object for a Falcon entity handle */
    public static function get(int $handle) : Entity{
        $player = falcon_entity_info($handle)["player"];
        if($player !== 0){
            return Players::get($player);
        }
        $id = falcon_entity_runtime_id($handle);
        $known = self::$byId[$id] ?? null;
        if($known !== null && !$known->isClosed()){
            return $known;
        }
        $info = falcon_entity_info($handle);
        $class = self::CLASSES[$info["type"]] ?? ($info["maxHealth"] > 0 ? GenericLiving::class : GenericEntity::class);
        return self::$byId[$id] = new $class($id);
    }

    public static function byId(int $runtimeId) : ?Entity{
        $handle = falcon_server_entity($runtimeId);
        return $handle === 0 ? null : self::get($handle);
    }

    /** Falcon's type of the stand-ins of plugins' entities, which no client sees */
    public const DRAWN_TYPE = "pmmpcompat:entity";
    /** where a stand-in keeps the saved data of its entity, base64 NBT: Falcon saves it with the chunk */
    private const DATA_KEY = "nbt";
    /** how often the entities a plugin made are saved onto their stand-ins, and saved ones looked for */
    private const SAVE_PERIOD = 100;
    private const ADOPT_PERIOD = 20;
    /** how often who sees what is worked out again */
    private const VIEW_PERIOD = 5;

    /** @var Entity[] the entities plugins made, by runtime id */
    private static array $drawn = [];
    /** @var Entity[] the ones whose onUpdate() runs next tick, by runtime id */
    private static array $updating = [];
    /** @var array<int, array<int, bool>> by entity id, then player object id: whether the player held its chunk */
    private static array $chunkHolders = [];
    /** @var array<int, int> by entity id: the chunk it was in when last looked at */
    private static array $chunks = [];
    /** a saved entity being made again: its stand-in, which the constructor takes instead of a new one */
    private static int $adopting = 0;
    private static bool $warnedUnsupported = false;

    /**
     * @internal a plugin's entity is being made: its stand-in, a Falcon entity no client sees, gives the runtime id
     */
    public static function place(Entity $entity) : int{
        $location = $entity->getLocation();
        $size = $entity->getSize();
        $handle = self::$adopting;
        self::$adopting = 0;
        if($handle !== 0){
            falcon_entity_teleport($handle, $location->getWorld()->getHandle(), $location->x, $location->y, $location->z);
            falcon_entity_set_size($handle, $size->getWidth(), $size->getHeight());
        }else{
            $handle = falcon_drawn_entity_spawn($location->getWorld()->getHandle(), $location->x, $location->y, $location->z, $size->getWidth(), $size->getHeight());
        }
        if($handle === 0){
            // an older Falcon: the entity is still drawn, only hits and clicks cannot reach it
            if(!self::$warnedUnsupported){
                self::$warnedUnsupported = true;
                Log::warning("This Falcon cannot hold the place of entities PocketMine-MP plugins define: they show, but clicks and hits do not reach them");
            }
            $id = Entity::nextRuntimeId();
        }else{
            $id = falcon_entity_runtime_id($handle);
        }
        self::$drawn[$id] = $entity;
        self::$byId[$id] = $entity;
        Bridge::listenForDrawnEntities();
        // whoever holds its chunk now sees it once spawnToAll() or spawnTo() says so, as on PocketMine-MP
        $holders = [];
        foreach($location->getWorld()->getPlayers() as $player){
            $holders[spl_object_id($player)] = $player->hasReceivedChunk($location->getFloorX() >> 4, $location->getFloorZ() >> 4);
        }
        self::$chunkHolders[$id] = $holders;
        self::$chunks[$id] = self::chunkHash($location->getFloorX() >> 4, $location->getFloorZ() >> 4);
        return $id;
    }

    /** @internal the entity closed: its stand-in goes with it */
    public static function remove(Entity $entity) : void{
        $id = $entity->getId();
        unset(self::$drawn[$id], self::$updating[$id], self::$chunkHolders[$id], self::$chunks[$id], self::$byId[$id]);
        $handle = falcon_server_entity($id);
        if($handle !== 0 && falcon_entity_is_drawn($handle)){
            falcon_entity_remove($handle);
        }
    }

    /** @internal its size changed: hits aim at the new box */
    public static function resize(Entity $entity) : void{
        $handle = $entity->isClosed() ? 0 : falcon_server_entity($entity->getId());
        if($handle !== 0){
            $size = $entity->getSize();
            falcon_entity_set_size($handle, $size->getWidth(), $size->getHeight());
        }
    }

    /** @internal */
    public static function scheduleUpdate(Entity $entity) : void{
        self::$updating[$entity->getId()] = $entity;
    }

    /** @internal the entity of a stand-in Falcon handed over, null for another entity */
    public static function drawnBy(int $handle) : ?Entity{
        return self::$drawn[falcon_entity_runtime_id($handle)] ?? null;
    }

    private static function chunkHash(int $chunkX, int $chunkZ) : int{
        return ($chunkX << 32) | ($chunkZ & 0xffffffff);
    }

    /** @internal each tick: the entities plugins made run, move and show to who holds their chunk */
    public static function tickDrawn(int $currentTick) : void{
        if($currentTick % self::ADOPT_PERIOD === 0){
            self::adoptSaved();
        }
        foreach(self::$drawn as $id => $entity){
            try{
                if($entity->isClosed()){
                    unset(self::$drawn[$id], self::$updating[$id]);
                    continue;
                }
                $handle = falcon_server_entity($id);
                if($handle === 0 && falcon_drawn_entities_supported()){
                    // Falcon let go of the stand-in, its chunk unloaded: what was saved comes back with the chunk
                    self::forgetDrawn($entity);
                    continue;
                }
                if(isset(self::$updating[$id]) && !$entity->onUpdate($currentTick)){
                    unset(self::$updating[$id]);
                }
                if($entity->isFlaggedForDespawn()){
                    $entity->close();
                    continue;
                }
                if($entity->isClosed()){
                    continue;
                }
                $location = $entity->getLocation();
                if($handle !== 0){
                    falcon_entity_teleport($handle, $location->getWorld()->getHandle(), $location->x, $location->y, $location->z);
                }
                if(($currentTick + $id) % self::VIEW_PERIOD === 0){
                    self::refreshViewers($entity);
                }
                if($handle !== 0 && ($currentTick + $id) % self::SAVE_PERIOD === 0){
                    self::save($entity, $handle);
                }
                self::touchPlayers($entity);
            }catch(\Throwable $e){
                Log::critical("An entity of a plugin, " . $entity . ", failed its tick: " . $e->getMessage());
                Log::exception($e);
                unset(self::$updating[$id]);
            }
        }
    }

    /** a stand-in that went with its chunk: the object is dropped without closing, its save stays with Falcon */
    private static function forgetDrawn(Entity $entity) : void{
        $id = $entity->getId();
        foreach($entity->getViewers() as $player){
            $entity->despawnFrom($player);
        }
        unset(self::$drawn[$id], self::$updating[$id], self::$chunkHolders[$id], self::$chunks[$id], self::$byId[$id]);
    }

    /**
     * PocketMine-MP shows an entity to a player when the player receives its chunk, or when it moves into a chunk
     * the player holds, and hides it when the player loses that chunk.
     */
    private static function refreshViewers(Entity $entity) : void{
        $id = $entity->getId();
        $location = $entity->getLocation();
        $chunkX = $location->getFloorX() >> 4;
        $chunkZ = $location->getFloorZ() >> 4;
        $chunk = self::chunkHash($chunkX, $chunkZ);
        $moved = (self::$chunks[$id] ?? $chunk) !== $chunk;
        self::$chunks[$id] = $chunk;
        $world = $location->getWorld();
        $holders = [];
        foreach($world->getPlayers() as $player){
            $key = spl_object_id($player);
            $has = $player->hasReceivedChunk($chunkX, $chunkZ);
            $had = self::$chunkHolders[$id][$key] ?? false;
            $holders[$key] = $has;
            if($has && (!$had || $moved) && !$entity->isFlaggedForDespawn()){
                $entity->spawnTo($player);
            }elseif(!$has){
                $entity->despawnFrom($player);
            }
        }
        self::$chunkHolders[$id] = $holders;
        foreach($entity->getViewers() as $player){
            // gone, or in another world: the client already forgot it
            if(!$player->isOnline() || $player->getWorld() !== $world){
                $entity->despawnFrom($player, false);
            }
        }
    }

    /** players walking into it, as PocketMine-MP's players do on each tick */
    private static function touchPlayers(Entity $entity) : void{
        $box = $entity->getBoundingBox();
        foreach($entity->getViewers() as $player){
            if($player->isOnline() && $player->isAlive() && $player->getBoundingBox()->expandedCopy(1, 0.5, 1)->intersectsWith($box)){
                $entity->onCollideWithPlayer($player);
            }
        }
    }

    /** writes the entity's saved data onto its stand-in, which Falcon saves with the chunk */
    private static function save(Entity $entity, int $handle) : void{
        $saved = null;
        if($entity->canSaveWithChunk() && !$entity instanceof NeverSavedWithChunkEntity && !$entity->isFlaggedForDespawn()
            && EntityFactory::getInstance()->isRegistered($entity::class)){
            $saved = base64_encode((new LittleEndianNbtSerializer())->write(new TreeRoot($entity->saveNBT())));
        }
        falcon_entity_set_data($handle, self::DATA_KEY, $saved);
    }

    /** @internal the server stops: every entity a plugin made is saved onto its stand-in one last time */
    public static function saveAll() : void{
        foreach(self::$drawn as $id => $entity){
            try{
                $handle = falcon_server_entity($id);
                if($handle !== 0 && !$entity->isClosed()){
                    self::save($entity, $handle);
                }
            }catch(\Throwable $e){
                Log::warning("Could not save the entity " . $entity . ": " . $e->getMessage());
            }
        }
    }

    /**
     * Stand-ins Falcon loaded with their chunk get their entity back from what was saved, as PocketMine-MP makes
     * entities again from a chunk's data; one nothing can be made from is removed.
     */
    private static function adoptSaved() : void{
        $factory = EntityFactory::getInstance();
        foreach(Server::getInstance()->getWorldManager()->getWorlds() as $world){
            foreach(falcon_level_entities($world->getHandle()) as $handle){
                $id = falcon_entity_runtime_id($handle);
                if(isset(self::$drawn[$id]) || falcon_entity_type($handle) !== self::DRAWN_TYPE){
                    continue;
                }
                $data = falcon_entity_data($handle, self::DATA_KEY);
                $entity = null;
                if($data !== null){
                    try{
                        $nbt = (new LittleEndianNbtSerializer())->read((string) base64_decode($data, true))->mustGetCompoundTag();
                        self::$adopting = $handle;
                        $entity = $factory->createFromData($world, $nbt);
                    }catch(\Throwable $e){
                        Log::warning("A saved entity could not be made again: " . $e->getMessage());
                    }finally{
                        self::$adopting = 0;
                    }
                }
                if($entity === null || $entity->getId() !== $id){
                    if($entity !== null && !$entity->isClosed()){
                        $entity->close();
                    }
                    $still = falcon_server_entity($id);
                    if($still !== 0){
                        falcon_entity_remove($still);
                    }
                }
            }
        }
    }

    /** objects for entities that are gone, dropped now and then */
    public static function forgetClosed() : void{
        foreach(self::$byId as $id => $entity){
            if(!isset(self::$drawn[$id]) && $entity->isClosed()){
                unset(self::$byId[$id]);
            }
        }
    }
}
