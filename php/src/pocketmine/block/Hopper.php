<?php

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\math\Facing;

/** A hopper; Falcon moves its items */
class Hopper extends Transparent{
    private const FACINGS = [0 => Facing::DOWN, 2 => Facing::NORTH, 3 => Facing::SOUTH, 4 => Facing::WEST, 5 => Facing::EAST];

    public function getFacing() : int{
        return self::FACINGS[(int) ($this->getStates()["facing_direction"] ?? 0)] ?? Facing::DOWN;
    }

    public function setFacing(int $facing) : self{
        $state = array_search($facing, self::FACINGS, true);
        if($state === false){
            throw new \InvalidArgumentException("Invalid hopper facing $facing");
        }
        $this->setState("facing_direction", $state);
        return $this;
    }

    public function isPowered() : bool{
        return (bool) ($this->getStates()["toggle_bit"] ?? false);
    }

    public function setPowered(bool $powered) : self{
        $this->setState("toggle_bit", $powered);
        return $this;
    }
}
