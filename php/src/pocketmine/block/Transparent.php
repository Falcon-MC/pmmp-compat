<?php

declare(strict_types=1);

namespace pocketmine\block;

/** A block light passes through: glass, leaves, slabs */
class Transparent extends Block{
    public function isTransparent() : bool{
        return true;
    }
}
