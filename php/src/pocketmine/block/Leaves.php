<?php

declare(strict_types=1);

namespace pocketmine\block;

class Leaves extends Transparent{
    public function isNoDecay() : bool{
        return (bool) ($this->getStates()["persistent_bit"] ?? false);
    }

    public function isCheckDecay() : bool{
        return (bool) ($this->getStates()["update_bit"] ?? false);
    }
}
