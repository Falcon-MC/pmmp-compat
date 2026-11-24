<?php

declare(strict_types=1);

namespace pmmpcompat\world;

use pocketmine\block\VanillaBlocks;
use pocketmine\block\tile\Tile;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
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

    public function isViewOf(World $world, int $chunkX, int $chunkZ) : bool{
        return $world === $this->world && $chunkX === $this->chunkX && $chunkZ === $this->chunkZ;
    }

    public function getHeight() : int{
        return Chunk::MAX_SUBCHUNKS;
    }

    public function getSubChunk(int $y) : SubChunk{
        if($y < self::MIN_SUBCHUNK_INDEX || $y > self::MAX_SUBCHUNK_INDEX){
            throw new \InvalidArgumentException("Invalid subchunk Y coordinate $y");
        }
        $x = $this->chunkX << self::COORD_BIT_SIZE;
        $z = $this->chunkZ << self::COORD_BIT_SIZE;
        $blockY = $y << SubChunk::COORD_BIT_SIZE;
        $air = VanillaBlocks::AIR()->getStateId();
        return new SubChunk($air, [
            PalettedBlockArray::forBlockLayer($this->world, $x, $blockY, $z, 0, $air),
            PalettedBlockArray::forBlockLayer($this->world, $x, $blockY, $z, 1, $air),
        ], new PalettedBlockArray(BiomeIds::OCEAN));
    }

    public function getSubChunks() : array{
        $subChunks = [];
        for($y = self::MIN_SUBCHUNK_INDEX; $y <= self::MAX_SUBCHUNK_INDEX; ++$y){
            $subChunks[$y] = $this->getSubChunk($y);
        }
        return $subChunks;
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
