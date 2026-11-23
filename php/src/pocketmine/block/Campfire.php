<?php

declare(strict_types=1);

namespace pocketmine\block;

use pmmpcompat\inventory\FalconBlockInventory;

class Campfire extends Transparent{
    public function isLit() : bool{
        return !($this->getStates()['extinguished'] ?? false);
    }

    public function getInventory() : FalconBlockInventory{
        return FalconBlockInventory::at($this->getPosition());
    }
}
