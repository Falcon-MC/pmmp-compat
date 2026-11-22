<?php

declare(strict_types=1);

namespace pocketmine\item;

/** An item thrown as a projectile */
abstract class ProjectileItem extends Item{
    public function getThrowForce() : float{
        return 1.5;
    }
}
