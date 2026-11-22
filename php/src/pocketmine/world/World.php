<?php

declare(strict_types=1);

namespace pocketmine\world;

use pmmpcompat\Entities;
use pmmpcompat\Players;
use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\format\Chunk;

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

    /** @internal WorldManager makes them, one for each of Falcon's levels */
    public function __construct(
        private string $folderName,
        private int $dimension,
        private int $level
    ){
        $this->id = self::$nextId++;
    }

    /** @internal the Falcon level this world stands for */
    public function getHandle() : int{
        return $this->level;
    }

    /** @internal */
    public function onUnload() : void{
        $this->loaded = false;
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
        falcon_level_set_block($this->level, $x, $y, $z, $block->getFalconIdentifier(), $block->getFalconStates());
    }

    public function setBlock(Vector3 $pos, Block $block, bool $update = true) : void{
        $this->setBlockAt((int) floor($pos->x), (int) floor($pos->y), (int) floor($pos->z), $block, $update);
    }

    /** breaks the block as a player would, its drops falling */
    public function useBreakOn(Vector3 $vector, ?Item &$item = null, ?Player $player = null, bool $createParticles = false) : bool{
        return falcon_level_break_block($this->level, (int) floor($vector->x), (int) floor($vector->y),
            (int) floor($vector->z), true);
    }

    /** chunks are Falcon's: no PocketMine-MP chunk object stands for them */
    public function getChunk(int $chunkX, int $chunkZ) : ?Chunk{
        return null;
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

    public function __toString() : string{
        return $this->folderName;
    }
}
