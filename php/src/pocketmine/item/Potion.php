<?php

declare(strict_types=1);

namespace pocketmine\item;

class Potion extends Item implements ConsumableItem{
    public function getMaxStackSize() : int{
        return 1;
    }

    public function getResidue() : Item{
        return VanillaItems::GLASS_BOTTLE();
    }

    public function getAdditionalEffects() : array{
        return [];
    }

    public function onConsume(\pocketmine\entity\Living $consumer) : void{
    }

    public function canStartUsingItem(\pocketmine\player\Player $player) : bool{
        return true;
    }
}
