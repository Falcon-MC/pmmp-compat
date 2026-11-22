<?php

declare(strict_types=1);

namespace pmmpcompat\inventory;

use pocketmine\block\inventory\BlockInventory;
use pocketmine\block\inventory\BlockInventoryTrait;
use pocketmine\block\inventory\BarrelInventory;
use pocketmine\block\inventory\ChestInventory;
use pocketmine\block\inventory\HopperInventory;
use pocketmine\block\inventory\ShulkerBoxInventory;
use pocketmine\world\Position;

/**
 * The items of a container block, Falcon's: read from and written to the block at its position.
 */
class FalconBlockInventory extends FalconSlotsInventory implements BlockInventory{
    use BlockInventoryTrait;

    public function __construct(Position $holder){
        $this->holder = $holder;
        parent::__construct();
    }

    /** the inventory class PocketMine-MP gives the block, a plain one for the others */
    public static function at(Position $position) : FalconBlockInventory{
        $name = $position->getWorld()->getBlock($position)->getFalconIdentifier();
        return match(true){
            str_ends_with($name, "chest") && $name !== "minecraft:ender_chest" => new ChestInventory($position),
            $name === "minecraft:barrel" => new BarrelInventory($position),
            $name === "minecraft:hopper" => new HopperInventory($position),
            str_ends_with($name, "shulker_box") => new ShulkerBoxInventory($position),
            default => new FalconBlockInventory($position),
        };
    }

    private function place() : array{
        $holder = $this->holder;
        return [$holder->getWorld()->getHandle(), $holder->getFloorX(), $holder->getFloorY(), $holder->getFloorZ()];
    }

    public function getSize() : int{
        [$level, $x, $y, $z] = $this->place();
        return falcon_level_container_size($level, $x, $y, $z);
    }

    protected function readSlot(int $index) : ?array{
        [$level, $x, $y, $z] = $this->place();
        return falcon_level_container_item($level, $x, $y, $z, $index);
    }

    protected function writeSlot(int $index, array $item) : void{
        [$level, $x, $y, $z] = $this->place();
        falcon_level_set_container_item($level, $x, $y, $z, $index, $item);
    }
}
