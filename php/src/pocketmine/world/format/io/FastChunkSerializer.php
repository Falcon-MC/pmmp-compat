<?php

declare(strict_types=1);

namespace pocketmine\world\format\io;

use pocketmine\utils\BinaryStream;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;

/** Serializes detached terrain for transfers within the running server, including editing snapshots. */
final class FastChunkSerializer{
    public static function serializeTerrain(Chunk $chunk) : string{
        $stream = new BinaryStream();
        $stream->putBool($chunk->isPopulated());
        foreach($chunk->getSubChunks() as $subChunk){
            $stream->putLInt($subChunk->getEmptyBlockId());
            $layers = $subChunk->getBlockLayers();
            $stream->putByte(count($layers));
            foreach($layers as $layer){
                $layer->writeSnapshot($stream);
            }
            $subChunk->getBiomeArray()->writeSnapshot($stream);
        }
        return $stream->getBuffer();
    }

    public static function deserializeTerrain(string $data) : Chunk{
        $stream = new BinaryStream($data);
        $populated = $stream->getBool();
        $subChunks = [];
        for($y = Chunk::MIN_SUBCHUNK_INDEX; $y <= Chunk::MAX_SUBCHUNK_INDEX; ++$y){
            $empty = $stream->getLInt() & 0xffffffff;
            $layerCount = $stream->getByte();
            $layers = [];
            for($i = 0; $i < $layerCount; ++$i){
                $layers[] = PalettedBlockArray::readSnapshot($stream);
            }
            $subChunks[$y] = new SubChunk($empty, $layers, PalettedBlockArray::readSnapshot($stream));
        }
        if(!$stream->feof()){
            throw new \UnexpectedValueException('Trailing data in terrain snapshot');
        }
        return new Chunk($subChunks, $populated);
    }
}
