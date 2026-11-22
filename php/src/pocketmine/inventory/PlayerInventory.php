<?php

declare(strict_types=1);

namespace pocketmine\inventory;

use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;

/**
 * A player's inventory, read from and written to Falcon's: hotbar first, then the rest.
 *
 * The rest of PocketMine-MP's inventory API, windows and listeners, comes with the inventories.
 */
class PlayerInventory{
    public function __construct(
        private Player $holder
    ){
    }

    public function getHolder() : Player{
        return $this->holder;
    }

    private function handle() : int{
        return $this->holder->isOnline() ? $this->holder->getHandle() : 0;
    }

    public function getSize() : int{
        $handle = $this->handle();
        return $handle === 0 ? 0 : falcon_player_inventory_size($handle);
    }

    public function getHotbarSize() : int{
        return 9;
    }

    public function getItem(int $index) : Item{
        $handle = $this->handle();
        $data = $handle === 0 ? null : falcon_player_inventory_item($handle, $index);
        return $data === null ? VanillaItems::AIR() : Item::fromFalcon($data);
    }

    public function setItem(int $index, Item $item) : void{
        $handle = $this->handle();
        if($handle !== 0){
            falcon_player_set_inventory_item($handle, $index, $item->isNull() ? VanillaItems::AIR()->setCount(0)->toFalcon() : $item->toFalcon());
        }
    }

    /**
     * @return Item[] by slot, the empty ones left out unless asked for
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

    /**
     * @param Item[] $items
     */
    public function setContents(array $items) : void{
        for($slot = 0, $size = $this->getSize(); $slot < $size; ++$slot){
            $this->setItem($slot, $items[$slot] ?? VanillaItems::AIR());
        }
    }

    public function clearAll() : void{
        $this->setContents([]);
    }

    public function clear(int $index) : void{
        $this->setItem($index, VanillaItems::AIR());
    }

    /**
     * Gives the items as picking them up would; what does not fit is given back.
     *
     * @return Item[] what did not fit
     */
    public function addItem(Item ...$slots) : array{
        $handle = $this->handle();
        $left = [];
        foreach($slots as $item){
            if($item->isNull()){
                continue;
            }
            if($handle === 0 || !falcon_player_give_item($handle, $item->toFalcon())){
                $left[] = $item;
            }
        }
        return $left;
    }

    public function canAddItem(Item $item) : bool{
        $room = 0;
        foreach($this->getContents(true) as $slotItem){
            if($slotItem->isNull()){
                $room += $item->getMaxStackSize();
            }elseif($slotItem->canStackWith($item)){
                $room += max(0, $slotItem->getMaxStackSize() - $slotItem->getCount());
            }
        }
        return $room >= $item->getCount();
    }

    public function contains(Item $item) : bool{
        $needed = $item->getCount();
        foreach($this->getContents() as $slotItem){
            if($slotItem->equals($item, true, $item->hasNamedTag())){
                $needed -= $slotItem->getCount();
                if($needed <= 0){
                    return true;
                }
            }
        }
        return false;
    }

    public function first(Item $item, bool $exact = false) : int{
        foreach($this->getContents() as $slot => $slotItem){
            if($exact ? $slotItem->equalsExact($item) : $slotItem->equals($item, true, $item->hasNamedTag())){
                return $slot;
            }
        }
        return -1;
    }

    public function firstEmpty() : int{
        foreach($this->getContents(true) as $slot => $item){
            if($item->isNull()){
                return $slot;
            }
        }
        return -1;
    }

    public function isSlotEmpty(int $index) : bool{
        return $this->getItem($index)->isNull();
    }

    public function getHeldItemIndex() : int{
        $handle = $this->handle();
        return $handle === 0 ? 0 : falcon_player_selected_slot($handle);
    }

    public function setHeldItemIndex(int $hotbarSlot) : void{
        if($hotbarSlot < 0 || $hotbarSlot >= $this->getHotbarSize()){
            throw new \InvalidArgumentException("Hotbar slot index \"$hotbarSlot\" is out of range");
        }
        $handle = $this->handle();
        if($handle !== 0){
            falcon_player_set_selected_slot($handle, $hotbarSlot);
        }
    }

    public function getItemInHand() : Item{
        return $this->getItem($this->getHeldItemIndex());
    }

    public function setItemInHand(Item $item) : void{
        $this->setItem($this->getHeldItemIndex(), $item);
    }
}
