<?php

declare(strict_types=1);

namespace pocketmine\block;

class DoublePlant extends Flowable{
    public function isTop() : bool{
        return (bool) ($this->getStates()['upper_block_bit'] ?? false);
    }

    public function setTop(bool $top) : self{
        $this->setState('upper_block_bit', $top);
        return $this;
    }
}
