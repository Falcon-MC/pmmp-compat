<?php

declare(strict_types=1);

namespace pocketmine\block;

/** A block with no collision: flowers, torches, rails */
class Flowable extends Transparent{
    public function canBeFlowedInto() : bool{
        return true;
    }

    public function isSolid() : bool{
        return false;
    }
}
