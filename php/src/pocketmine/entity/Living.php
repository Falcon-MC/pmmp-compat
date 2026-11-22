<?php

declare(strict_types=1);

namespace pocketmine\entity;

/** An entity with health that can die: mobs and players */
abstract class Living extends Entity{
    public const DEFAULT_KNOCKBACK_FORCE = 0.4;
    public const DEFAULT_KNOCKBACK_VERTICAL_LIMIT = 0.4;

    public function getEyeHeight() : float{
        return 1.62;
    }
}
