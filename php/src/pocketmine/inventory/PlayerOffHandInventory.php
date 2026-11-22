<?php

declare(strict_types=1);

namespace pocketmine\inventory;

use pmmpcompat\inventory\FalconSlotsInventory;
use pocketmine\entity\Human;
use pocketmine\player\Player;

/** The item in a player's off hand, Falcon's */
final class PlayerOffHandInventory extends FalconSlotsInventory{
    public function __construct(
        private Human $holder
    ){
        parent::__construct();
    }

    private function handle() : int{
        return $this->holder instanceof Player && $this->holder->isOnline() ? $this->holder->getHandle() : 0;
    }

    public function getSize() : int{
        return 1;
    }

    protected function readSlot(int $index) : ?array{
        $handle = $this->handle();
        return $handle === 0 ? null : falcon_player_offhand_item($handle);
    }

    protected function writeSlot(int $index, array $item) : void{
        $handle = $this->handle();
        if($handle !== 0){
            falcon_player_set_offhand_item($handle, $item);
        }
    }

    public function getHolder() : Human{
        return $this->holder;
    }
}
