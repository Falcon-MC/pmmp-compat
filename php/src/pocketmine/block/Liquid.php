<?php

declare(strict_types=1);

namespace pocketmine\block;

/** Water or lava, still or flowing; Falcon makes it flow */
abstract class Liquid extends Transparent{
    public const MAX_DECAY = 7;

    public function canBeReplaced() : bool{
        return true;
    }

    public function isSolid() : bool{
        return false;
    }

    public function isSource() : bool{
        return (int) ($this->getStates()["liquid_depth"] ?? 0) === 0;
    }

    public function getDecay() : int{
        return ((int) ($this->getStates()["liquid_depth"] ?? 0)) & 0x7;
    }

    public function isFalling() : bool{
        return (((int) ($this->getStates()["liquid_depth"] ?? 0)) & 0x8) !== 0;
    }

    public function isStill() : bool{
        return !str_starts_with($this->getFalconIdentifier(), "minecraft:flowing_");
    }
}
