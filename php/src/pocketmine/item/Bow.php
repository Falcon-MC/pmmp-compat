<?php

declare(strict_types=1);

namespace pocketmine\item;

class Bow extends Durable implements Releasable{
    public function getFuelTime() : int{
        return 200;
    }

    public function getMaxStackSize() : int{
        return 1;
    }

    public function canStartUsingItem(\pocketmine\player\Player $player) : bool{
        return !$player->hasFiniteResources() || $player->getOffHandInventory()->contains(VanillaItems::ARROW()) || $player->getInventory()->contains(VanillaItems::ARROW());
    }
}
