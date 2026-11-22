<?php

declare(strict_types=1);

namespace pocketmine\block;

/** A full block light does not pass through */
class Opaque extends Block{
    public function isSolid() : bool{
        return true;
    }
}
