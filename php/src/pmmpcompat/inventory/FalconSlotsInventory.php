<?php

declare(strict_types=1);

namespace pmmpcompat\inventory;

use pocketmine\inventory\BaseInventory;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;

/**
 * An inventory whose slots are Falcon's: read from and written to Falcon at each call, never kept here.
 *
 * PocketMine-MP's listeners and validators work on it as on any inventory; Falcon keeps the client in step.
 */
abstract class FalconSlotsInventory extends BaseInventory{
    /** what an empty slot is written as */
    protected const EMPTY = ["id" => "minecraft:air", "count" => 0, "damage" => 0, "nbt" => ""];

    /** @var array<int, array{id: string, count: int, damage: int, nbt: string}> what an entity a plugin made holds: Falcon keeps nothing for it */
    protected array $kept = [];

    /** @return array{id: string, count: int, damage: int, nbt: string}|null */
    abstract protected function readSlot(int $index) : ?array;

    /** @param array{id: string, count: int, damage: int, nbt: string} $item */
    abstract protected function writeSlot(int $index, array $item) : void;

    public function getItem(int $index) : Item{
        $data = $index >= 0 && $index < $this->getSize() ? $this->readSlot($index) : null;
        return $data === null ? VanillaItems::AIR() : Item::fromFalcon($data);
    }

    /**
     * @return Item[]
     */
    public function getContents(bool $includeEmpty = false) : array{
        $contents = [];
        for($slot = 0, $size = $this->getSize(); $slot < $size; ++$slot){
            $item = $this->getItem($slot);
            if($includeEmpty || !$item->isNull()){
                $contents[$slot] = $item;
            }
        }
        return $contents;
    }

    protected function internalSetItem(int $index, Item $item) : void{
        $this->writeSlot($index, $item->isNull() ? self::EMPTY : $item->toFalcon());
    }

    /**
     * @param Item[] $items
     */
    protected function internalSetContents(array $items) : void{
        for($slot = 0, $size = $this->getSize(); $slot < $size; ++$slot){
            $item = $items[$slot] ?? null;
            $this->writeSlot($slot, $item === null || $item->isNull() ? self::EMPTY : $item->toFalcon());
        }
    }
}
