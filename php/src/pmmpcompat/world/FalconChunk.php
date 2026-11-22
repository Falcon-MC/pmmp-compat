<?php

declare(strict_types=1);

namespace pmmpcompat\world;

use pocketmine\block\tile\Tile;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;

/**
 * A view of one of Falcon's chunks: what is read goes to the world, the chunk's data stays Falcon's.
 */
final class FalconChunk extends Chunk{
    public function __construct(
        private World $world,
        private int $chunkX,
        private int $chunkZ
    ){
        // Falcon keeps the sub-chunks: PocketMine-MP's are not made
    }

    public function getHeight() : int{
        return Chunk::MAX_SUBCHUNKS;
    }

    private function worldX(int $x) : int{
        return ($this->chunkX << Chunk::COORD_BIT_SIZE) | ($x & Chunk::COORD_MASK);
    }

    private function worldZ(int $z) : int{
        return ($this->chunkZ << Chunk::COORD_BIT_SIZE) | ($z & Chunk::COORD_MASK);
    }

    public function getBlockStateId(int $x, int $y, int $z) : int{
        return $this->world->getBlockAt($this->worldX($x), $y, $this->worldZ($z))->getStateId();
    }

    public function setBlockStateId(int $x, int $y, int $z, int $block) : void{
        $state = \pocketmine\block\Block::stateOf($block);
        if($state !== null){
            falcon_level_set_block($this->world->getHandle(), $this->worldX($x), $y, $this->worldZ($z), $state[0], $state[1]);
        }
    }

    public function getHighestBlockAt(int $x, int $z) : ?int{
        return $this->world->getHighestBlockAt($this->worldX($x), $this->worldZ($z));
    }

    public function getHeightMap(int $x, int $z) : int{
        return ($this->getHighestBlockAt($x, $z) ?? World::Y_MIN) + 1;
    }

    public function setHeightMap(int $x, int $z, int $value) : void{
    }

    public function isLightPopulated() : ?bool{
        return true;
    }

    public function setLightPopulated(?bool $value = true) : void{
    }

    public function isPopulated() : bool{
        return true;
    }

    public function setPopulated(bool $value = true) : void{
    }

    public function addTile(Tile $tile) : void{
    }

    public function removeTile(Tile $tile) : void{
    }

    public function getTiles() : array{
        return $this->world->getChunkTiles($this->chunkX, $this->chunkZ);
    }

    public function getTile(int $x, int $y, int $z) : ?Tile{
        return $this->world->getTileAt($this->worldX($x), $y, $this->worldZ($z));
    }

    public function onUnload() : void{
    }

    public function isTerrainDirty() : bool{
        return false;
    }

    public function getTerrainDirtyFlag(int $flag) : bool{
        return false;
    }

    public function getTerrainDirtyFlags() : int{
        return 0;
    }

    public function setTerrainDirtyFlag(int $flag, bool $value) : void{
    }

    public function setTerrainDirty() : void{
    }

    public function clearTerrainDirtyFlags() : void{
    }
}
