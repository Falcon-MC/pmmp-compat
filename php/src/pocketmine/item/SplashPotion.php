<?php

declare(strict_types=1);

namespace pocketmine\item;

class SplashPotion extends ProjectileItem{
    public function getMaxStackSize() : int{
        return 1;
    }
}
