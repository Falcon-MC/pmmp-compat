<?php

declare(strict_types=1);

namespace pocketmine\item;

class Snowball extends ProjectileItem{
    public function getMaxStackSize() : int{
        return 16;
    }
}
