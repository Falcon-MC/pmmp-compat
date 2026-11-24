<?php

declare(strict_types=1);

namespace pmmpcompat\world;

use pocketmine\block\Block;
use pocketmine\block\tile\TileFactory;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use pocketmine\world\World;

/** Detached terrain editing for plugins that otherwise replace PocketMine-MP's chunk internals. */
final class ChunkEdit{
    public static function snapshot(World $world, int $chunkX, int $chunkZ) : Chunk{
        $source = $world->getChunk($chunkX, $chunkZ) ?? throw new \RuntimeException("Chunk $chunkX, $chunkZ is unavailable");
        $subChunks = [];
        foreach($source->getSubChunks() as $y => $subChunk){
            $layers = $subChunk->getBlockLayers();
            $empty = $subChunk->getEmptyBlockId();
            $layers[0] ??= new PalettedBlockArray($empty);
            foreach($layers as $layer => $palette){
                $layers[$layer] = $palette->snapshot($layer === 0);
            }
            $subChunks[$y] = new SubChunk($empty, $layers, $subChunk->getBiomeArray()->snapshot());
        }
        return new Chunk($subChunks, $source->isPopulated());
    }

    /** @param array<int, Chunk> $chunks by World::chunkHash() */
    public static function apply(World $world, array $chunks) : void{
        $edits = [];
        $tiles = TileFactory::getInstance();
        foreach($chunks as $hash => $chunk){
            World::getXZ($hash, $chunkX, $chunkZ);
            if(!$world->isChunkLoaded($chunkX, $chunkZ)){
                throw new \RuntimeException("Chunk $chunkX, $chunkZ unloaded during editing");
            }
            foreach($chunk->getSubChunks() as $subY => $subChunk){
                $primary = $subChunk->getBlockLayers()[0] ?? throw new \InvalidArgumentException('Expected a terrain snapshot');
                foreach($primary->getOriginalEntries() as $index => $original){
                    $localX = $index >> 8;
                    $localY = $index & 15;
                    $localZ = ($index >> 4) & 15;
                    $x = ($chunkX << 4) + $localX;
                    $y = ($subY << 4) + $localY;
                    $z = ($chunkZ << 4) + $localZ;
                    if(!$world->isInWorld($x, $y, $z)){
                        throw new \RuntimeException("Block $x, $y, $z is outside the world");
                    }
                    $current = $world->getBlockAt($x, $y, $z);
                    if($current->getStateId() !== $original){
                        throw new \RuntimeException("Block $x, $y, $z changed while the edit was running");
                    }
                    $state = Block::stateOf($primary->get($localX, $localY, $localZ));
                    if($state === null || falcon_block_network_id($state[0], $state[1]) === 0){
                        throw new \RuntimeException("Unknown replacement block at $x, $y, $z");
                    }
                    if($tiles->getClassForBlock($state[0]) !== null || $world->getTileAt($x, $y, $z) !== null){
                        throw new \RuntimeException("Block entity at $x, $y, $z cannot be edited");
                    }
                    $edits[] = [$x, $y, $z, Block::fromFalcon($state[0], $state[1])];
                }
            }
        }
        foreach($edits as [$x, $y, $z, $block]){
            if(!$world->writeBlockAt($x, $y, $z, $block)){
                throw new \RuntimeException("Falcon rejected the block write at $x, $y, $z");
            }
        }
    }
}
