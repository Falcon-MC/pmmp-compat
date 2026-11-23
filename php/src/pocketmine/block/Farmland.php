<?php

declare(strict_types=1);

namespace pocketmine\block;

class Farmland extends Transparent{
    public const MAX_WETNESS = 7;

    public function getWetness() : int{
        return (int) ($this->getStates()['moisturized_amount'] ?? 0);
    }

    /** @return $this */
    public function setWetness(int $wetness) : self{
        if($wetness < 0 || $wetness > self::MAX_WETNESS){
            throw new \InvalidArgumentException('Wetness must be in range 0 ... ' . self::MAX_WETNESS);
        }
        $this->setState('moisturized_amount', $wetness);
        return $this;
    }
}
