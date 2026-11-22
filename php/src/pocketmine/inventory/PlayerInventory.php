<?php

declare(strict_types=1);

namespace pocketmine\inventory;

use pmmpcompat\inventory\FalconSlotsInventory;
use pocketmine\entity\Human;
use pocketmine\item\Item;
use pocketmine\player\Player;
use pocketmine\utils\ObjectSet;

/** A player's inventory, Falcon's: the hotbar is its first nine slots */
class PlayerInventory extends FalconSlotsInventory{
    /** @var ObjectSet<\Closure(int $oldIndex) : void> */
    protected ObjectSet $heldItemIndexChangeListeners;

    public function __construct(
        protected Human $holder
    ){
        $this->heldItemIndexChangeListeners = new ObjectSet();
        parent::__construct();
    }

    private function handle() : int{
        return $this->holder instanceof Player && $this->holder->isOnline() ? $this->holder->getHandle() : 0;
    }

    public function getSize() : int{
        $handle = $this->handle();
        return $handle === 0 ? 36 : falcon_player_inventory_size($handle);
    }

    protected function readSlot(int $index) : ?array{
        $handle = $this->handle();
        return $handle === 0 ? null : falcon_player_inventory_item($handle, $index);
    }

    protected function writeSlot(int $index, array $item) : void{
        $handle = $this->handle();
        if($handle !== 0){
            falcon_player_set_inventory_item($handle, $index, $item);
        }
    }

    public function isHotbarSlot(int $slot) : bool{
        return $slot >= 0 && $slot < $this->getHotbarSize();
    }

    private function throwIfNotHotbarSlot(int $slot) : void{
        if(!$this->isHotbarSlot($slot)){
            throw new \InvalidArgumentException("$slot is not a valid hotbar slot index (expected 0 - " . ($this->getHotbarSize() - 1) . ")");
        }
    }

    public function getHotbarSlotItem(int $hotbarSlot) : Item{
        $this->throwIfNotHotbarSlot($hotbarSlot);
        return $this->getItem($hotbarSlot);
    }

    public function getHeldItemIndex() : int{
        $handle = $this->handle();
        return $handle === 0 ? 0 : falcon_player_selected_slot($handle);
    }

    public function setHeldItemIndex(int $hotbarSlot) : void{
        $this->throwIfNotHotbarSlot($hotbarSlot);
        $old = $this->getHeldItemIndex();
        $handle = $this->handle();
        if($handle !== 0){
            falcon_player_set_selected_slot($handle, $hotbarSlot);
        }
        foreach($this->heldItemIndexChangeListeners as $callback){
            $callback($old);
        }
    }

    /**
     * @return ObjectSet<\Closure(int $oldIndex) : void>
     */
    public function getHeldItemIndexChangeListeners() : ObjectSet{
        return $this->heldItemIndexChangeListeners;
    }

    public function getItemInHand() : Item{
        return $this->getHotbarSlotItem($this->getHeldItemIndex());
    }

    public function setItemInHand(Item $item) : void{
        $this->setItem($this->getHeldItemIndex(), $item);
    }

    public function getHotbarSize() : int{
        return 9;
    }

    public function getHolder() : Human{
        return $this->holder;
    }
}
