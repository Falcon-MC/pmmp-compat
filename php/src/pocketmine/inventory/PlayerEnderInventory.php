<?php

declare(strict_types=1);

namespace pocketmine\inventory;

use pmmpcompat\inventory\FalconSlotsInventory;
use pocketmine\entity\Human;
use pocketmine\player\Player;

/** A player's Ender chest, Falcon's: the same from any Ender chest */
final class PlayerEnderInventory extends FalconSlotsInventory{
    public function __construct(
        private Human $holder,
        private int $size = 27
    ){
        parent::__construct();
    }

    private function handle() : int{
        return $this->holder instanceof Player && $this->holder->isOnline() ? $this->holder->getHandle() : 0;
    }

    public function getSize() : int{
        $handle = $this->handle();
        return $handle === 0 ? $this->size : falcon_player_ender_size($handle);
    }

    protected function readSlot(int $index) : ?array{
        $handle = $this->handle();
        return $handle === 0 ? null : falcon_player_ender_item($handle, $index);
    }

    protected function writeSlot(int $index, array $item) : void{
        $handle = $this->handle();
        if($handle !== 0){
            falcon_player_set_ender_item($handle, $index, $item);
        }
    }

    public function getHolder() : Human{
        return $this->holder;
    }
}
