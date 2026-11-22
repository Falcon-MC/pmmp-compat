<?php

declare(strict_types=1);

namespace pocketmine\block;

class Air extends Transparent{
    public function canBeFlowedInto() : bool{
        return true;
    }

    public function canBeReplaced() : bool{
        return true;
    }

    public function isSolid() : bool{
        return false;
    }
}
