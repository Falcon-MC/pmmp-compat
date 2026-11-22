<?php

declare(strict_types=1);

namespace pocketmine\block\tile;

use pocketmine\block\Block;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\Position;
use pocketmine\world\World;

/**
 * A block entity: the chest, sign or furnace data at a block.
 *
 * The tiles of Falcon's blocks are Falcon's: these objects read and write them there. A tile a plugin makes for its
 * own class lives here, in this world, while the server runs.
 */
abstract class Tile{
    public const TAG_ID = "id";
    public const TAG_X = "x";
    public const TAG_Y = "y";
    public const TAG_Z = "z";

    protected Position $position;
    public bool $closed = false;

    public function __construct(World $world, Vector3 $pos){
        $this->position = Position::fromObject($pos->floor(), $world);
    }

    /** @internal reads what a plugin's own tile saved */
    public function readSaveData(CompoundTag $nbt) : void{
    }

    protected function writeSaveData(CompoundTag $nbt) : void{
    }

    public function saveNBT() : CompoundTag{
        $nbt = CompoundTag::create()
            ->setString(self::TAG_ID, TileFactory::getInstance()->getSaveId(get_class($this)))
            ->setInt(self::TAG_X, $this->position->getFloorX())
            ->setInt(self::TAG_Y, $this->position->getFloorY())
            ->setInt(self::TAG_Z, $this->position->getFloorZ());
        $this->writeSaveData($nbt);
        return $nbt;
    }

    public function getCleanedNBT() : ?CompoundTag{
        $this->writeSaveData($tag = new CompoundTag());
        return $tag->count() > 0 ? $tag : null;
    }

    /** @internal what an item that places this block carries for it */
    public function copyDataFromItem(Item $item) : void{
    }

    public function getBlock() : Block{
        return $this->position->getWorld()->getBlock($this->position);
    }

    public function getPosition() : Position{
        return $this->position;
    }

    public function isClosed() : bool{
        return $this->closed;
    }

    protected function onBlockDestroyedHook() : void{
    }

    public function onBlockDestroyed() : void{
        $this->onBlockDestroyedHook();
        $this->close();
    }

    public function close() : void{
        if(!$this->closed){
            $this->closed = true;
            if($this->position->isValid()){
                $this->position->getWorld()->removeTile($this);
            }
        }
    }
}
