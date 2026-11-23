<?php

declare(strict_types=1);

namespace pocketmine\world;

use pmmpcompat\Entities;
use pmmpcompat\Players;
use pmmpcompat\world\FalconChunk;
use pocketmine\block\Block;
use pocketmine\block\tile\Spawnable;
use pocketmine\block\tile\Tile;
use pocketmine\block\tile\TileFactory;
use pocketmine\math\AxisAlignedBB;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\utils\ReversePriorityQueue;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\world\format\Chunk;
use pocketmine\world\particle\Particle;
use pocketmine\world\sound\Sound;

/**
 * A world, standing for one of Falcon's levels.
 *
 * PocketMine-MP has one dimension a world; Falcon's worlds have three. The overworld keeps the world's name, the
 * other two are worlds named after it, "<world> nether" and "<world> the_end".
 */
class World implements ChunkManager{
    public const Y_MIN = -64;
    public const Y_MAX = 320;
    public const TIME_DAY = 1000;
    public const TIME_NOON = 6000;
    public const TIME_SUNSET = 12000;
    public const TIME_NIGHT = 13000;
    public const TIME_MIDNIGHT = 18000;
    public const TIME_SUNRISE = 23000;
    public const TIME_FULL = 24000;

    private const DIMENSION_OVERWORLD = 0;
    private const DIMENSION_NETHER = 1;

    private static int $nextId = 1;

    private int $id;
    private bool $loaded = true;
    /** @var array<int, array<int, ChunkListener>> */
    private array $chunkListeners = [];
    /** @var ReversePriorityQueue<int, Vector3> */
    private ReversePriorityQueue $scheduledBlockUpdateQueue;
    /** @var array<int, true> */
    private array $scheduledBlockUpdates = [];

    /** @internal WorldManager makes them, one for each of Falcon's levels */
    public function __construct(
        private string $folderName,
        private int $dimension,
        private int $level
    ){
        $this->id = self::$nextId++;
        $this->scheduledBlockUpdateQueue = new ReversePriorityQueue();
        $this->scheduledBlockUpdateQueue->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
    }

    /** @internal the Falcon level this world stands for */
    public function getHandle() : int{
        return $this->level;
    }

    /** @internal */
    public function onUnload() : void{
        $this->loaded = false;
        $this->chunkListeners = [];
        $this->scheduledBlockUpdates = [];
        $this->scheduledBlockUpdateQueue = new ReversePriorityQueue();
        $this->scheduledBlockUpdateQueue->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
    }

    public function getServer() : \pocketmine\Server{
        return \pocketmine\Server::getInstance();
    }

    public function getId() : int{
        return $this->id;
    }

    public function isLoaded() : bool{
        return $this->loaded;
    }

    public function getFolderName() : string{
        return $this->folderName;
    }

    public function getDisplayName() : string{
        return $this->folderName;
    }

    public function getMinY() : int{
        return $this->dimension === self::DIMENSION_OVERWORLD ? self::Y_MIN : 0;
    }

    public function getMaxY() : int{
        return match($this->dimension){
            self::DIMENSION_OVERWORLD => self::Y_MAX,
            self::DIMENSION_NETHER => 128,
            default => 256,
        };
    }

    public function isInWorld(int $x, int $y, int $z) : bool{
        return $y >= $this->getMinY() && $y < $this->getMaxY();
    }

    public function getBlockAt(int $x, int $y, int $z, bool $cached = true, bool $addToCache = true) : Block{
        if(!$this->isInWorld($x, $y, $z)){
            return VanillaBlocks::AIR()->withPosition($this, $x, $y, $z);
        }
        [$name, $states] = falcon_level_get_block($this->level, $x, $y, $z);
        return Block::fromFalcon($name, $states)->withPosition($this, $x, $y, $z);
    }

    public function getBlock(Vector3 $pos, bool $cached = true, bool $addToCache = true) : Block{
        return $this->getBlockAt((int) floor($pos->x), (int) floor($pos->y), (int) floor($pos->z), $cached, $addToCache);
    }

    /**
     * @param bool $update kept for PocketMine-MP's signature: Falcon updates the neighbours either way
     */
    public function setBlockAt(int $x, int $y, int $z, Block $block, bool $update = true) : void{
        if(!$this->isInWorld($x, $y, $z)){
            throw new \InvalidArgumentException("Pos x=$x,y=$y,z=$z is outside of the world bounds");
        }
        $listeners = $this->chunkListeners[self::chunkHash($x >> 4, $z >> 4)] ?? [];
        $oldState = $listeners === [] ? null : $this->getBlockAt($x, $y, $z)->getStateId();
        $changed = falcon_level_set_block($this->level, $x, $y, $z, $block->getFalconIdentifier(), $block->getFalconStates());
        if($changed && $oldState !== null && $this->getBlockAt($x, $y, $z)->getStateId() !== $oldState){
            $pos = new Vector3($x, $y, $z);
            foreach($listeners as $listener){
                $listener->onBlockChanged($pos);
            }
        }
    }

    public function setBlock(Vector3 $pos, Block $block, bool $update = true) : void{
        $this->setBlockAt((int) floor($pos->x), (int) floor($pos->y), (int) floor($pos->z), $block, $update);
    }

    /** breaks the block as a player would, its drops falling */
    public function useBreakOn(Vector3 $vector, ?Item &$item = null, ?Player $player = null, bool $createParticles = false) : bool{
        return falcon_level_break_block($this->level, (int) floor($vector->x), (int) floor($vector->y),
            (int) floor($vector->z), true);
    }

    /** a view of the chunk while Falcon has it loaded */
    public function getChunk(int $chunkX, int $chunkZ) : ?Chunk{
        return $this->isChunkLoaded($chunkX, $chunkZ) ? new FalconChunk($this, $chunkX, $chunkZ) : null;
    }

    public function getOrLoadChunkAtPosition(Vector3 $pos) : ?Chunk{
        return $this->getChunk(((int) floor($pos->x)) >> Chunk::COORD_BIT_SIZE, ((int) floor($pos->z)) >> Chunk::COORD_BIT_SIZE);
    }

    public function loadChunk(int $chunkX, int $chunkZ) : ?Chunk{
        return $this->getChunk($chunkX, $chunkZ);
    }

    /** how far around each player chunks are looked for: Falcon does not list the ones it holds */
    private const LOADED_CHUNK_RADIUS = 10;

    /**
     * The chunks loaded around the players of this world, and around its spawn, by World::chunkHash().
     * @return Chunk[]
     */
    public function getLoadedChunks() : array{
        $centres = [$this->getSpawnLocation()];
        foreach($this->getPlayers() as $player){
            $centres[] = $player->getPosition();
        }
        $chunks = [];
        foreach($centres as $centre){
            $centreX = ((int) floor($centre->x)) >> Chunk::COORD_BIT_SIZE;
            $centreZ = ((int) floor($centre->z)) >> Chunk::COORD_BIT_SIZE;
            for($x = $centreX - self::LOADED_CHUNK_RADIUS; $x <= $centreX + self::LOADED_CHUNK_RADIUS; ++$x){
                for($z = $centreZ - self::LOADED_CHUNK_RADIUS; $z <= $centreZ + self::LOADED_CHUNK_RADIUS; ++$z){
                    $hash = self::chunkHash($x, $z);
                    if(!isset($chunks[$hash]) && $this->isChunkLoaded($x, $z)){
                        $chunks[$hash] = new FalconChunk($this, $x, $z);
                    }
                }
            }
        }
        return $chunks;
    }

    public function registerChunkListener(ChunkListener $listener, int $chunkX, int $chunkZ) : void{
        $this->chunkListeners[self::chunkHash($chunkX, $chunkZ)][spl_object_id($listener)] = $listener;
        \pmmpcompat\Bridge::listenFor(\pocketmine\event\world\ChunkLoadEvent::class);
        \pmmpcompat\Bridge::listenFor(\pocketmine\event\world\ChunkUnloadEvent::class);
    }

    public function unregisterChunkListener(ChunkListener $listener, int $chunkX, int $chunkZ) : void{
        $hash = self::chunkHash($chunkX, $chunkZ);
        unset($this->chunkListeners[$hash][spl_object_id($listener)]);
        if(($this->chunkListeners[$hash] ?? []) === []){
            unset($this->chunkListeners[$hash]);
        }
    }

    /** @internal Dispatch real chunk lifecycle events for listeners registered through World. */
    public function notifyChunkListeners(int $chunkX, int $chunkZ, Chunk $chunk, bool $loaded) : void{
        $hash = self::chunkHash($chunkX, $chunkZ);
        $listeners = $this->chunkListeners[$hash] ?? [];
        if(!$loaded){
            unset($this->chunkListeners[$hash]);
        }
        foreach($listeners as $listener){
            if($loaded){
                $listener->onChunkLoaded($chunkX, $chunkZ, $chunk);
            }else{
                $listener->onChunkUnloaded($chunkX, $chunkZ, $chunk);
            }
        }
    }

    public function scheduleDelayedBlockUpdate(Vector3 $pos, int $delay) : void{
        if($delay < 0){
            throw new \InvalidArgumentException("Block update delay must be non-negative");
        }
        $pos = $pos->floor();
        if(!$this->isInWorld((int) $pos->x, (int) $pos->y, (int) $pos->z)){
            return;
        }
        $hash = self::blockHash((int) $pos->x, (int) $pos->y, (int) $pos->z);
        if(!isset($this->scheduledBlockUpdates[$hash])){
            $this->scheduledBlockUpdates[$hash] = true;
            $this->scheduledBlockUpdateQueue->insert($pos, $this->getServer()->getTick() + max(1, $delay));
        }
    }

    /** @internal Run PHP block callbacks after plugin schedulers have observed this tick's queue. */
    public function tickScheduledBlockUpdates(int $currentTick) : void{
        while(!$this->scheduledBlockUpdateQueue->isEmpty() && $this->scheduledBlockUpdateQueue->top()["priority"] <= $currentTick){
            $pos = $this->scheduledBlockUpdateQueue->extract()["data"];
            unset($this->scheduledBlockUpdates[self::blockHash((int) $pos->x, (int) $pos->y, (int) $pos->z)]);
            if($this->isChunkLoaded(((int) $pos->x) >> 4, ((int) $pos->z) >> 4)){
                $this->getBlock($pos)->onScheduledUpdate();
            }
        }
    }

    public static function chunkHash(int $x, int $z) : int{
        return (($x & 0xFFFFFFFF) << 32) | ($z & 0xFFFFFFFF);
    }

    public static function getXZ(int $hash, ?int &$x, ?int &$z) : void{
        $x = $hash >> 32;
        $z = ($hash & 0xFFFFFFFF) << 32 >> 32;
    }

    /** a block's coordinates in one int, 21 bits each; getBlockXYZ() reads them back */
    public static function blockHash(int $x, int $y, int $z) : int{
        return (($x & 0x1FFFFF) << 42) | (($y & 0x1FFFFF) << 21) | ($z & 0x1FFFFF);
    }

    public static function getBlockXYZ(int $hash, ?int &$x, ?int &$y, ?int &$z) : void{
        $x = ($hash >> 42) << 43 >> 43;
        $y = (($hash >> 21) & 0x1FFFFF) << 43 >> 43;
        $z = ($hash & 0x1FFFFF) << 43 >> 43;
    }

    public static function chunkBlockHash(int $x, int $y, int $z) : int{
        return self::blockHash($x, $y, $z);
    }

    public function isChunkGenerated(int $chunkX, int $chunkZ) : bool{
        return $this->isChunkLoaded($chunkX, $chunkZ);
    }

    public function isChunkPopulated(int $chunkX, int $chunkZ) : bool{
        return $this->isChunkLoaded($chunkX, $chunkZ);
    }

    /** @var Tile[] the tiles made so far, by position hash */
    private array $tiles = [];

    private static function tileHash(int $x, int $y, int $z) : string{
        return "$x:$y:$z";
    }

    /** the tile at a block: a plugin's own, or one standing for the block entity Falcon keeps there */
    public function getTileAt(int $x, int $y, int $z) : ?Tile{
        $hash = self::tileHash($x, $y, $z);
        $tile = $this->tiles[$hash] ?? null;
        $identifier = $this->getBlockAt($x, $y, $z)->getFalconIdentifier();
        $class = TileFactory::getInstance()->getClassForBlock($identifier);
        if($tile !== null && !$tile->isClosed() && (!$this->isFalconTile($tile) || ($class !== null && $tile instanceof $class))){
            return $tile;
        }
        unset($this->tiles[$hash]);
        if($class === null){
            return null;
        }
        return $this->tiles[$hash] = new $class($this, new Vector3($x, $y, $z));
    }

    /** a tile class TileFactory gives Falcon's blocks, rather than one a plugin made */
    private function isFalconTile(Tile $tile) : bool{
        return str_starts_with(get_class($tile), "pocketmine\\block\\tile\\");
    }

    public function getTile(Vector3 $pos) : ?Tile{
        return $this->getTileAt((int) floor($pos->x), (int) floor($pos->y), (int) floor($pos->z));
    }

    public function addTile(Tile $tile) : void{
        if($tile->isClosed()){
            throw new \InvalidArgumentException("Attempted to add a garbage closed Tile to a world");
        }
        $pos = $tile->getPosition();
        if(!$pos->isValid() || $pos->getWorld() !== $this){
            throw new \InvalidArgumentException("Invalid Tile world");
        }
        $this->tiles[self::tileHash($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())] = $tile;
    }

    public function removeTile(Tile $tile) : void{
        $pos = $tile->getPosition();
        $hash = self::tileHash($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
        if(($this->tiles[$hash] ?? null) === $tile){
            unset($this->tiles[$hash]);
        }
    }

    /**
     * @return Tile[] the tiles made so far in that chunk
     * @internal
     */
    public function getChunkTiles(int $chunkX, int $chunkZ) : array{
        $tiles = [];
        foreach($this->tiles as $tile){
            $pos = $tile->getPosition();
            if(($pos->getFloorX() >> Chunk::COORD_BIT_SIZE) === $chunkX && ($pos->getFloorZ() >> Chunk::COORD_BIT_SIZE) === $chunkZ){
                $tiles[] = $tile;
            }
        }
        return $tiles;
    }

    /**
     * @return Entity[] the entities whose position is in the box
     */
    public function getNearbyEntities(AxisAlignedBB $bb, ?Entity $entity = null) : array{
        $nearby = [];
        foreach($this->getEntities() as $id => $other){
            if($other === $entity){
                continue;
            }
            $pos = $other->getPosition();
            if($pos->x >= $bb->minX && $pos->x <= $bb->maxX && $pos->y >= $bb->minY && $pos->y <= $bb->maxY
                && $pos->z >= $bb->minZ && $pos->z <= $bb->maxZ){
                $nearby[$id] = $other;
            }
        }
        return $nearby;
    }

    /**
     * The boxes of the blocks in the box that entities collide with, as Falcon has them.
     *
     * @return AxisAlignedBB[]
     */
    public function getBlockCollisionBoxes(AxisAlignedBB $bb) : array{
        $boxes = [];
        foreach(falcon_level_collision_boxes($this->level, $bb->minX, $bb->minY, $bb->minZ, $bb->maxX, $bb->maxY, $bb->maxZ) as [$minX, $minY, $minZ, $maxX, $maxY, $maxZ]){
            $boxes[] = new AxisAlignedBB($minX, $minY, $minZ, $maxX, $maxY, $maxZ);
        }
        return $boxes;
    }

    /**
     * @return AxisAlignedBB[]
     */
    public function getCollisionBoxes(Entity $entity, AxisAlignedBB $bb, bool $entities = true) : array{
        $boxes = $this->getBlockCollisionBoxes($bb);
        if($entities){
            foreach($this->getCollidingEntities($bb->expandedCopy(0.25, 0.25, 0.25), $entity) as $other){
                $boxes[] = clone $other->getBoundingBox();
            }
        }
        return $boxes;
    }

    /**
     * @return Entity[]
     */
    public function getCollidingEntities(AxisAlignedBB $bb, ?Entity $entity = null) : array{
        return $this->getNearbyEntities($bb, $entity);
    }

    /**
     * @phpstan-param class-string<Entity> $entityType
     */
    public function getNearestEntity(Vector3 $pos, float $maxDistance, string $entityType = Entity::class, bool $includeDead = false) : ?Entity{
        $nearest = null;
        $best = $maxDistance ** 2;
        foreach($this->getEntities() as $entity){
            if(!$entity instanceof $entityType || (!$includeDead && !$entity->isAlive() && $entity instanceof \pocketmine\entity\Living)){
                continue;
            }
            $distance = $entity->getPosition()->distanceSquared($pos);
            if($distance < $best){
                $best = $distance;
                $nearest = $entity;
            }
        }
        return $nearest;
    }

    public function getSafeSpawn(?Vector3 $spawn = null) : Position{
        $spawn ??= $this->getSpawnLocation();
        $x = (int) floor($spawn->x);
        $z = (int) floor($spawn->z);
        $y = $this->getHighestBlockAt($x, $z);
        return new Position($spawn->x, $y === null ? $spawn->y : $y + 1, $spawn->z, $this);
    }

    public function isRaining() : bool{
        return falcon_level_weather($this->level)[0];
    }

    public function isThundering() : bool{
        return falcon_level_weather($this->level)[1];
    }

    /** sets the weather of the world, as Falcon's /weather does */
    public function setWeather(bool $raining, bool $thundering = false) : void{
        falcon_level_set_weather($this->level, $raining, $thundering);
    }

    /** an explosion Falcon makes: its damage, knockback and broken blocks are Falcon's */
    public function createExplosion(Vector3 $pos, float $size, bool $breakBlocks = true) : void{
        falcon_level_explode($this->level, $pos->x, $pos->y, $pos->z, $size, $breakBlocks);
    }

    public function strikeLightning(Vector3 $pos) : void{
        falcon_level_lightning($this->level, $pos->x, $pos->y, $pos->z);
    }

    public function setChunk(int $chunkX, int $chunkZ, Chunk $chunk) : void{
        throw new \LogicException("Falcon keeps this world's chunks: setChunk() is not available");
    }

    public function isChunkLoaded(int $chunkX, int $chunkZ) : bool{
        return falcon_level_is_chunk_loaded($this->level, $chunkX, $chunkZ);
    }

    public function getHighestBlockAt(int $x, int $z) : ?int{
        $y = falcon_level_highest_block($this->level, $x, $z);
        return $y < $this->getMinY() ? null : $y;
    }

    public function getSpawnLocation() : Position{
        [$x, $y, $z] = falcon_level_spawn($this->level);
        return new Position($x, $y, $z, $this);
    }

    public function getTime() : int{
        return falcon_level_time($this->level);
    }

    public function getTimeOfDay() : int{
        return $this->getTime() % self::TIME_FULL;
    }

    public function setTime(int $time) : void{
        falcon_level_set_time($this->level, $time);
    }

    /**
     * @return Player[] by entity id
     */
    public function getPlayers() : array{
        $players = [];
        foreach(Players::online() as $player){
            if($player->getWorld() === $this){
                $players[$player->getId()] = $player;
            }
        }
        return $players;
    }

    /**
     * @return Entity[] by entity id
     */
    public function getEntities() : array{
        $entities = [];
        foreach(falcon_level_entities($this->level) as $handle){
            $entity = Entities::get($handle);
            $entities[$entity->getId()] = $entity;
        }
        return $entities;
    }

    public function getEntity(int $entityId) : ?Entity{
        $entity = Entities::byId($entityId);
        return $entity !== null && $entity->getWorld() === $this ? $entity : null;
    }

    /**
     * Drops an item as a player would; Falcon gives the item entity its own motion and pickup delay.
     *
     * @return null the item entity is Falcon's, no PocketMine-MP object stands for it
     */
    public function dropItem(Vector3 $source, Item $item, ?Vector3 $motion = null, int $delay = 10) : mixed{
        if(!$item->isNull()){
            falcon_level_drop_item($this->level, $source->x, $source->y, $source->z, $item->toFalcon());
        }
        return null;
    }

    /** how far a sound or a particle carries: the players who would have the chunk loaded */
    private const VIEW_DISTANCE = 64;

    /**
     * @return Player[] the players near enough to see what happens there
     */
    public function getViewersForPosition(Vector3 $pos) : array{
        $viewers = [];
        foreach($this->getPlayers() as $id => $player){
            if($player->getPosition()->distanceSquared($pos) <= self::VIEW_DISTANCE ** 2){
                $viewers[$id] = $player;
            }
        }
        return $viewers;
    }

    /**
     * @param list<Vector3> $positions
     * @return list<ClientboundPacket>
     */
    public function createBlockUpdatePackets(array $positions) : array{
        $packets = [];
        $translator = TypeConverter::getInstance()->getBlockTranslator();
        foreach($positions as $position){
            $block = $this->getBlock($position);
            $networkPosition = BlockPosition::fromVector3($block->getPosition());
            $packets[] = UpdateBlockPacket::create(
                $networkPosition, $translator->internalIdToNetworkId($block->getStateId()),
                UpdateBlockPacket::FLAG_NETWORK,
                UpdateBlockPacket::DATA_LAYER_NORMAL
            );
            $tile = $this->getTile($position);
            if($tile instanceof Spawnable){
                $packets[] = BlockActorDataPacket::create(
                    $networkPosition, new CacheableNbt($tile->getSerializedSpawnCompound())
                );
            }
        }
        return $packets;
    }

    public function broadcastPacketToViewers(Vector3 $pos, ClientboundPacket $packet) : void{
        $viewers = $this->getViewersForPosition($pos);
        if(count($viewers) > 0){
            NetworkBroadcastUtils::broadcastPackets($viewers, [$packet]);
        }
    }

    /**
     * @param Player[]|null $players everyone near when null
     */
    public function addSound(Vector3 $pos, Sound $sound, ?array $players = null) : void{
        $packets = $sound->encode($pos);
        $players ??= $this->getViewersForPosition($pos);
        if(count($packets) > 0 && count($players) > 0){
            NetworkBroadcastUtils::broadcastPackets($players, $packets);
        }
    }

    /**
     * @param Player[]|null $players everyone near when null
     */
    public function addParticle(Vector3 $pos, Particle $particle, ?array $players = null) : void{
        $packets = $particle->encode($pos);
        $players ??= $this->getViewersForPosition($pos);
        if(count($packets) > 0 && count($players) > 0){
            NetworkBroadcastUtils::broadcastPackets($players, $packets);
        }
    }

    public function __toString() : string{
        return $this->folderName;
    }
}
