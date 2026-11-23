<?php

declare(strict_types=1);

namespace pocketmine\inventory;

use pmmpcompat\inventory\FalconSlotsInventory;
use pocketmine\entity\Living;
use pocketmine\item\Item;
use pocketmine\player\Player;

/** What a player wears, Falcon's: head, chest, legs, feet */
class ArmorInventory extends FalconSlotsInventory{
    public const SLOT_HEAD = 0;
    public const SLOT_CHEST = 1;
    public const SLOT_LEGS = 2;
    public const SLOT_FEET = 3;

    public function __construct(
        protected Living $holder
    ){
        parent::__construct();
    }

    private function handle() : int{
        return $this->holder instanceof Player && $this->holder->isOnline() ? $this->holder->getHandle() : 0;
    }

    public function getSize() : int{
        return 4;
    }

    protected function readSlot(int $index) : ?array{
        $handle = $this->handle();
        return $handle === 0 ? ($this->holder instanceof Player ? null : $this->kept[$index] ?? null) : falcon_player_armor_item($handle, $index);
    }

    protected function writeSlot(int $index, array $item) : void{
        $handle = $this->handle();
        if($handle !== 0){
            falcon_player_set_armor_item($handle, $index, $item);
        }elseif(!$this->holder instanceof Player){
            $this->kept[$index] = $item;
        }
    }

    public function getHolder() : Living{
        return $this->holder;
    }

    public function getHelmet() : Item{
        return $this->getItem(self::SLOT_HEAD);
    }

    public function getChestplate() : Item{
        return $this->getItem(self::SLOT_CHEST);
    }

    public function getLeggings() : Item{
        return $this->getItem(self::SLOT_LEGS);
    }

    public function getBoots() : Item{
        return $this->getItem(self::SLOT_FEET);
    }

    public function setHelmet(Item $helmet) : void{
        $this->setItem(self::SLOT_HEAD, $helmet);
    }

    public function setChestplate(Item $chestplate) : void{
        $this->setItem(self::SLOT_CHEST, $chestplate);
    }

    public function setLeggings(Item $leggings) : void{
        $this->setItem(self::SLOT_LEGS, $leggings);
    }

    public function setBoots(Item $boots) : void{
        $this->setItem(self::SLOT_FEET, $boots);
    }
}
