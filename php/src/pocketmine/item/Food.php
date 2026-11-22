<?php

declare(strict_types=1);

namespace pocketmine\item;

/** Something to eat; Falcon feeds the player when they finish eating it */
class Food extends Item implements FoodSourceItem{
    private function info() : array{
        return \pmmpcompat\ItemTypes::item($this->getFalconIdentifier()) ?? [];
    }

    public function getFoodRestore() : int{
        return (int) ($this->info()["nutrition"] ?? 0);
    }

    public function getSaturationRestore() : float{
        return (float) ($this->info()["saturation"] ?? 0.0);
    }

    public function requiresHunger() : bool{
        return true;
    }

    public function getResidue() : Item{
        return str_ends_with($this->getFalconIdentifier(), "_stew") || str_ends_with($this->getFalconIdentifier(), "_soup")
            ? VanillaItems::BOWL() : VanillaItems::AIR();
    }

    public function getAdditionalEffects() : array{
        return [];
    }

    public function onConsume(\pocketmine\entity\Living $consumer) : void{
    }

    public function canStartUsingItem(\pocketmine\player\Player $player) : bool{
        return !$this->requiresHunger() || $player->getHungerManager()->isHungry();
    }
}
