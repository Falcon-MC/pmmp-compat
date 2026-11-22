<?php

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\tile\Tile;
use pocketmine\utils\Utils;

/** The type id of a block a plugin defines, and the tile class it carries */
class BlockIdentifier{
    /**
     * @phpstan-param class-string<Tile>|null $tileClass
     */
    public function __construct(
        private int $blockTypeId,
        private ?string $tileClass = null
    ){
        if($blockTypeId < 0){
            throw new \InvalidArgumentException("Block type ID may not be negative");
        }
        if($tileClass !== null){
            Utils::testValidInstance($tileClass, Tile::class);
        }
    }

    public function getBlockTypeId() : int{
        return $this->blockTypeId;
    }

    /** @phpstan-return class-string<Tile>|null */
    public function getTileClass() : ?string{
        return $this->tileClass;
    }
}
