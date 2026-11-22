<?php

declare(strict_types=1);

namespace pmmpcompat\world;

use pocketmine\world\ChunkManager;
use pocketmine\world\generator\Generator;

/** Falcon's own generators: Falcon generates the chunks, this class only names them */
final class FalconGenerator extends Generator{
    public function generateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{
        //NOOP
    }

    public function populateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{
        //NOOP
    }
}
