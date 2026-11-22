<?php

declare(strict_types=1);

namespace pocketmine\item;

class Bucket extends Item{
    public function getMaxStackSize() : int{
        return $this->getFalconIdentifier() === "minecraft:bucket" ? 16 : 1;
    }
}
