<?php

declare(strict_types=1);

namespace pocketmine\block\inventory;

use pmmpcompat\inventory\FalconBlockInventory;

class BrewingStandInventory extends FalconBlockInventory{
    public const SLOT_INGREDIENT = 0;
    public const SLOT_BOTTLE_LEFT = 1;
    public const SLOT_BOTTLE_MIDDLE = 2;
    public const SLOT_BOTTLE_RIGHT = 3;
    public const SLOT_FUEL = 4;

    /** A brewing stand stores bottles first in Falcon and the ingredient first in PocketMine-MP. */
    private const NATIVE_SLOTS = [3, 0, 1, 2, 4];
    private const PHP_SLOTS = [1, 2, 3, 0, 4];

    public function fromNativeSlot(int $index) : int{
        return self::PHP_SLOTS[$index] ?? $index;
    }

    protected function readSlot(int $index) : ?array{
        return parent::readSlot(self::NATIVE_SLOTS[$index] ?? $index);
    }

    protected function writeSlot(int $index, array $item) : void{
        parent::writeSlot(self::NATIVE_SLOTS[$index] ?? $index, $item);
    }
}
