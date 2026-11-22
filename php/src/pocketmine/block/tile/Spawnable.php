<?php

declare(strict_types=1);

namespace pocketmine\block\tile;

use pocketmine\nbt\tag\CompoundTag;

/** A tile the players are sent; Falcon sends its own tiles' data itself */
abstract class Spawnable extends Tile{
    private bool $dirty = true;

    public function isDirty() : bool{
        return $this->dirty;
    }

    public function setDirty(bool $dirty = true) : void{
        $this->dirty = $dirty;
    }

    public function clearSpawnCompoundCache() : void{
        $this->dirty = true;
    }

    final public function getSerializedSpawnCompound(?object $typeConverter = null) : CompoundTag{
        $nbt = CompoundTag::create()
            ->setString(self::TAG_ID, TileFactory::getInstance()->getSaveId(get_class($this)))
            ->setInt(self::TAG_X, $this->position->getFloorX())
            ->setInt(self::TAG_Y, $this->position->getFloorY())
            ->setInt(self::TAG_Z, $this->position->getFloorZ());
        $this->addAdditionalSpawnData($nbt);
        return $nbt;
    }

    protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
    }
}
